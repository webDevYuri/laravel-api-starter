<?php

namespace App\Http\Controllers\Client\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PasswordResetService;
use App\Services\PendingRegistrationService;
use App\Support\ApiResponse;
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
            return ApiResponse::error('ACCOUNT_NOT_FOUND', 'No account was found for this email.', status: 404);
        }

        if (! $user->password || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'The password is incorrect.', status: 401);
        }

        if (! $user->email_verified_at) {
            return ApiResponse::error('EMAIL_NOT_VERIFIED', 'Please verify your email before logging in.', status: 403);
        }

        return ApiResponse::success('AUTHENTICATED', 'Login successful.', [
            'action' => 'login',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => UserResource::make($user->load('profile')),
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
            return ApiResponse::error('EMAIL_ALREADY_REGISTERED', 'This email is already registered.', status: 409);
        }

        $pending = $this->pendingRegistrationService->create($data, $email);
        $otp = $this->otpService->sendForRegistration($pending);

        $response = [
            'registrationId' => $pending->id,
            'email' => $email,
            'retryAfter' => config('otp.resend_after'),
            'resendAvailableAt' => $otp['challenge']->last_sent_at->addSeconds(config('otp.resend_after'))->toISOString(),
            'expiresAt' => $pending->expires_at->toISOString(),
        ];

        if (app()->environment('local')) {
            $response['otp'] = $otp['code'];
        }

        return ApiResponse::success('EMAIL_VERIFICATION_REQUIRED', 'A verification code has been sent to your email.', $response, status: 202);
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
            'registrationId' => $pending->id,
            'email' => $pending->email,
            'retryAfter' => config('otp.resend_after'),
            'resendAvailableAt' => $otp['challenge']->last_sent_at->addSeconds(config('otp.resend_after'))->toISOString(),
            'expiresAt' => $pending->expires_at->toISOString(),
        ];

        if (app()->environment('local')) {
            $response['otp'] = $otp['code'];
        }

        return ApiResponse::success('OTP_RESENT', 'A new verification code has been sent.', $response);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = $request->validate(['registrationId' => ['required', 'uuid'], 'code' => ['required', 'digits:'.config('otp.length')]]);
        $pending = PendingRegistration::find($data['registrationId']);

        if (! $pending || $pending->expires_at->isPast()) {
            return $this->registrationNotActiveResponse();
        }

        if (User::where('email', $pending->email)->exists()) {
            return ApiResponse::error('EMAIL_ALREADY_REGISTERED', 'This email is already registered.', status: 409);
        }

        $this->otpService->verifyForRegistration($pending, $data['code']);
        $user = $this->pendingRegistrationService->complete($pending);

        return ApiResponse::success('REGISTERED', 'Registration successful.', [
            'action' => 'register',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => UserResource::make($user->load('profile')),
        ], status: 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = strtolower($request->validate(['email' => ['required', 'email']])['email']);
        $result = $this->passwordResetService->sendLink($email);
        $response = ['email' => $email];

        if (app()->environment('local') && $result) {
            $response['token'] = $result['token'];
            $response['resetUrl'] = $result['resetUrl'];
            $response['expiresAt'] = $result['expiresAt']->toISOString();
        }

        return ApiResponse::success('PASSWORD_RESET_LINK_SENT', 'If an account exists for this email, a password reset link has been sent.', $response);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $reset = $this->passwordResetService->reset(
            strtolower($data['email']),
            $data['token'],
            $data['password'],
        );

        if (! $reset) {
            return ApiResponse::error('PASSWORD_RESET_NOT_ACTIVE', 'This password reset link is invalid or expired. Please request a new one.', status: 410);
        }

        return ApiResponse::success('PASSWORD_RESET', 'Your password has been reset successfully.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = $request->user();

        if (! $user->password || ! Hash::check($data['currentPassword'], $user->password)) {
            return ApiResponse::error('CURRENT_PASSWORD_INCORRECT', 'The current password is incorrect.', status: 422);
        }

        if (Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('PASSWORD_UNCHANGED', 'The new password must be different from the current password.', status: 422);
        }

        $user->update(['password' => $data['password']]);

        return ApiResponse::success('PASSWORD_CHANGED', 'Your password has been changed successfully.');
    }

    private function registrationNotActiveResponse(): JsonResponse
    {
        return ApiResponse::error('REGISTRATION_NOT_ACTIVE', 'This registration is no longer active. Please start a new registration.', status: 410);
    }
}
