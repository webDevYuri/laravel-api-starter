<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PasswordResetService;
use App\Services\PendingRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly PendingRegistrationService $pendingRegistrationService,
        private readonly PasswordResetService $passwordResetService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user) {
            return response()->json([
                'code' => 'ACCOUNT_NOT_FOUND',
                'message' => 'No account was found for this email.',
            ], 404);
        }

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'The password is incorrect.',
            ], 401);
        }

        if (! $user->email_verified_at) {
            return response()->json([
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Please verify your email before logging in.',
            ], 403);
        }

        return response()->json([
            'code' => 'AUTHENTICATED', 'message' => 'Login successful.', 'action' => 'login',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => [
                'id' => $user->id, 'email' => $user->email,
                'profile' => $user->profile ? [
                    'fname' => $user->profile->fname, 'mname' => $user->profile->mname, 'lname' => $user->profile->lname,
                ] : null,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8', 'confirmed'],
            'fname' => ['required', 'string', 'max:255'], 'mname' => ['nullable', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
        ]);
        $email = strtolower($data['email']);

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'code' => 'EMAIL_ALREADY_REGISTERED', 'message' => 'This email is already registered.',
            ], 409);
        }

        $pending = $this->pendingRegistrationService->create($data, $email);
        $otp = $this->otpService->sendForRegistration($pending);

        $response = [
            'code' => 'EMAIL_VERIFICATION_REQUIRED',
            'message' => 'A verification code has been sent to your email.',
            'registrationId' => $pending->id,
            'email' => $email,
            'retryAfter' => config('otp.resend_after'),
            'resendAvailableAt' => $otp['challenge']->last_sent_at->addSeconds(config('otp.resend_after'))->toISOString(),
            'expiresAt' => $pending->expires_at->toISOString(),
        ];

        if (app()->environment('local')) {
            $response['otp'] = $otp['code'];
        }

        return response()->json($response, 202);
    }

    public function resendRegistrationOtp(Request $request): JsonResponse
    {
        $registrationId = $request->validate(['registrationId' => ['required', 'uuid']])['registrationId'];
        $pending = PendingRegistration::find($registrationId);

        if (! $pending || $pending->expires_at->isPast()) {
            return $this->registrationNotActiveResponse();
        }

        $otp = $this->otpService->sendForRegistration($pending);
        $response = [
            'code' => 'OTP_RESENT',
            'message' => 'A new verification code has been sent.',
            'registrationId' => $pending->id,
            'email' => $pending->email,
            'retryAfter' => config('otp.resend_after'),
            'resendAvailableAt' => $otp['challenge']->last_sent_at->addSeconds(config('otp.resend_after'))->toISOString(),
            'expiresAt' => $pending->expires_at->toISOString(),
        ];

        if (app()->environment('local')) {
            $response['otp'] = $otp['code'];
        }

        return response()->json($response);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registrationId' => ['required', 'uuid'],
            'code' => ['required', 'digits:'.config('otp.length')],
        ]);
        $pending = PendingRegistration::find($data['registrationId']);

        if (! $pending || $pending->expires_at->isPast()) {
            return $this->registrationNotActiveResponse();
        }

        if (User::where('email', $pending->email)->exists()) {
            return response()->json([
                'code' => 'EMAIL_ALREADY_REGISTERED', 'message' => 'This email is already registered.',
            ], 409);
        }

        $this->otpService->verifyForRegistration($pending, $data['code']);
        $user = $this->pendingRegistrationService->complete($pending);

        return response()->json([
            'code' => 'REGISTERED', 'message' => 'Registration successful.', 'action' => 'register',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => ['id' => $user->id, 'email' => $user->email, 'profile' => [
                'fname' => $user->profile->fname, 'mname' => $user->profile->mname, 'lname' => $user->profile->lname,
            ]],
        ], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = strtolower($request->validate(['email' => ['required', 'email']])['email']);
        $result = $this->passwordResetService->sendLink($email);
        $response = [
            'code' => 'PASSWORD_RESET_LINK_SENT',
            'message' => 'If an account exists for this email, a password reset link has been sent.',
            'email' => $email,
        ];

        if (app()->environment('local') && $result) {
            $response['token'] = $result['token'];
            $response['resetUrl'] = $result['resetUrl'];
            $response['expiresAt'] = $result['expiresAt']->toISOString();
        }

        return response()->json($response);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $reset = $this->passwordResetService->reset(
            strtolower($data['email']),
            $data['token'],
            $data['password'],
        );

        if (! $reset) {
            return response()->json([
                'code' => 'PASSWORD_RESET_NOT_ACTIVE',
                'message' => 'This password reset link is invalid or expired. Please request a new one.',
            ], 410);
        }

        return response()->json([
            'code' => 'PASSWORD_RESET',
            'message' => 'Your password has been reset successfully.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = $request->user();

        if (! $user->password || ! Hash::check($data['currentPassword'], $user->password)) {
            return response()->json([
                'code' => 'CURRENT_PASSWORD_INCORRECT',
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        if (Hash::check($data['password'], $user->password)) {
            return response()->json([
                'code' => 'PASSWORD_UNCHANGED',
                'message' => 'The new password must be different from the current password.',
            ], 422);
        }

        $user->update(['password' => $data['password']]);

        return response()->json([
            'code' => 'PASSWORD_CHANGED',
            'message' => 'Your password has been changed successfully.',
        ]);
    }

    private function registrationNotActiveResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'REGISTRATION_NOT_ACTIVE',
            'message' => 'This registration is no longer active. Please start a new registration.',
        ], 410);
    }
}
