<?php

namespace App\Http\Controllers\Client\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpService;
use App\Services\UserRegistrationService;
use App\Support\ApiResponse;
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

        return ApiResponse::success('OTP_SENT', 'Verification code sent.', $this->otpResponse($result['challenge'], $action, $result['code']));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:'.config('otp.length')]]);
        $email = strtolower($data['email']);
        $challenge = $this->otpService->verify($email, 'login', $data['code']);
        $user = $challenge->user;
        $challenge->update(['consumed_at' => now()]);

        return ApiResponse::success('AUTHENTICATED', 'Login successful.', [
            'action' => 'login',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => UserResource::make($user->load('profile')),
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
            return ApiResponse::error('EMAIL_ALREADY_REGISTERED', 'This email is already registered.', status: 409);
        }

        $challenge = $this->otpService->verify($email, 'register', $data['code']);
        $user = $this->registrationService->register($data, $email, $challenge);

        return ApiResponse::success('REGISTERED', 'Registration successful.', [
            'action' => 'register',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => UserResource::make($user->load('profile')),
        ], status: 201);
    }

    private function otpResponse($challenge, string $action, string $code): array
    {
        $response = [
            'action' => $action,
            'email' => $challenge->identifier, 'retryAfter' => config('otp.resend_after'),
            'resendAvailableAt' => $challenge->last_sent_at->addSeconds(config('otp.resend_after'))->toISOString(),
            'expiresAt' => $challenge->expires_at->toISOString(),
        ];
        if (app()->environment('local')) {
            $response['otp'] = $code;
        }

        return $response;
    }
}
