<?php

namespace App\Http\Controllers\Admin\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
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
