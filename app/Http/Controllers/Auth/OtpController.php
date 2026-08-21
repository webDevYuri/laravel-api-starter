<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\UserRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly UserRegistrationService $registrationService,
    ) {}

    public function otp(Request $request): JsonResponse
    {
        $email = strtolower($request->validate(['email' => ['required', 'email']])['email']);
        $user = User::where('email', $email)->first();
        $action = $user ? 'login' : 'register';
        $result = $this->otpService->send($email, $action, $user);

        return response()->json($this->otpResponse($result['challenge'], $action, $result['code']));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:'.config('otp.length')]]);
        $email = strtolower($data['email']);
        $challenge = $this->otpService->verify($email, 'login', $data['code']);
        $user = $challenge->user;
        $challenge->update(['consumed_at' => now()]);

        return response()->json([
            'code' => 'AUTHENTICATED', 'message' => 'Login successful.', 'action' => 'login',
            'token' => $user->createToken('api')->plainTextToken, 'user' => $this->userResponse($user),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'code' => ['required', 'digits:'.config('otp.length')],
            'fname' => ['required', 'string', 'max:255'], 'mname' => ['nullable', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
        ]);
        $email = strtolower($data['email']);

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'code' => 'EMAIL_ALREADY_REGISTERED',
                'message' => 'This email is already registered.',
            ], 409);
        }

        $challenge = $this->otpService->verify($email, 'register', $data['code']);
        $user = $this->registrationService->register($data, $email, $challenge);

        return response()->json([
            'code' => 'REGISTERED', 'message' => 'Registration successful.', 'action' => 'register',
            'token' => $user->createToken('api')->plainTextToken, 'user' => $this->userResponse($user),
        ], 201);
    }

    private function otpResponse($challenge, string $action, string $code): array
    {
        $response = [
            'code' => 'OTP_SENT', 'message' => 'Verification code sent.', 'action' => $action,
            'email' => $challenge->identifier, 'retryAfter' => config('otp.resend_after'),
            'resendAvailableAt' => $challenge->last_sent_at->addSeconds(config('otp.resend_after'))->toISOString(),
            'expiresAt' => $challenge->expires_at->toISOString(),
        ];
        if (app()->environment('local')) {
            $response['otp'] = $code;
        }

        return $response;
    }

    private function userResponse(User $user): array
    {
        $profile = $user->profile;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'profile' => $profile ? [
                'fname' => $profile->fname,
                'mname' => $profile->mname,
                'lname' => $profile->lname,
            ] : null,
        ];
    }
}
