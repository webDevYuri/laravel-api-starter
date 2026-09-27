<?php

namespace App\Http\Controllers\Admin\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Services\AdminTwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthenticationController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if (! $user || ! $user->is_platform_admin || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error(
                'INVALID_ADMIN_CREDENTIALS',
                'The admin email or password is incorrect.',
                status: 401,
            );
        }

        if (! $user->email_verified_at) {
            return ApiResponse::error(
                'EMAIL_NOT_VERIFIED',
                'Please verify your email before logging in.',
                status: 403,
            );
        }

        if ($user->two_factor_enabled) {
            $token = $user->createToken('admin-2fa-pending', ['admin:2fa:verify'], now()->addMinutes(5));
            return ApiResponse::success('ADMIN_2FA_REQUIRED', 'Enter your two-factor authentication code.', [
                'action' => 'admin_2fa_verify',
                'token' => $token->plainTextToken,
                'expiresAt' => now()->addMinutes(5)->toISOString(),
            ]);
        }

        return $this->authenticated($user);
    }

    public function setup(Request $request, AdminTwoFactorService $twoFactor): JsonResponse
    {
        $user = $request->user();
        if ($user->two_factor_enabled) {
            return ApiResponse::error('ADMIN_2FA_ALREADY_ENABLED', 'Two-factor authentication is already enabled.', status: 409);
        }
        return ApiResponse::success('ADMIN_2FA_SETUP_READY', 'Scan the QR code, then verify the code from your authenticator app.', $twoFactor->setup($user));
    }

    public function verify(Request $request, AdminTwoFactorService $twoFactor): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if (! $token || ! in_array('admin:2fa:verify', $token->abilities, true) || ($token->expires_at && $token->expires_at->isPast())) {
            return ApiResponse::error('ADMIN_2FA_VERIFICATION_REQUIRED', 'Use the temporary token returned by admin login.', status: 401);
        }
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6,20}$/']]);
        if (! $twoFactor->verifyLogin($request->user(), $data['code'])) {
            return ApiResponse::error('INVALID_ADMIN_2FA_CODE', 'The two-factor authentication code is invalid or expired.', status: 422);
        }
        $token->delete();
        return $this->authenticated($request->user());
    }

    public function confirmSetup(Request $request, AdminTwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        if (! $twoFactor->verifySetup($request->user(), $data['code'])) {
            return ApiResponse::error('INVALID_ADMIN_2FA_CODE', 'The two-factor authentication code is invalid.', status: 422);
        }
        return ApiResponse::success('ADMIN_2FA_ENABLED', 'Two-factor authentication has been enabled.');
    }

    public function disable(Request $request, AdminTwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6,20}$/']]);
        if (! $twoFactor->verifyLogin($request->user(), $data['code'])) {
            return ApiResponse::error('INVALID_ADMIN_2FA_CODE', 'The two-factor authentication code is invalid.', status: 422);
        }
        $twoFactor->disable($request->user());
        return ApiResponse::success('ADMIN_2FA_DISABLED', 'Two-factor authentication has been disabled.');
    }

    private function authenticated(User $user): JsonResponse
    {
        return ApiResponse::success('ADMIN_AUTHENTICATED', 'Admin login successful.', [
            'action' => 'admin_login',
            'token' => $user->createToken('admin-api', ['admin'])->plainTextToken,
            'user' => UserResource::make($user->load('profile')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success('ADMIN_LOGGED_OUT', 'Admin logged out successfully.');
    }
}
