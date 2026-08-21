<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticatedSessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('CURRENT_USER', 'Current user retrieved.', [
            'user' => UserResource::make($request->user()->load('profile')),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fname' => ['sometimes', 'required', 'string', 'max:255'],
            'mname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lname' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        if ($data === []) {
            return ApiResponse::error('PROFILE_FIELDS_REQUIRED', 'At least one profile field is required.', status: 422);
        }

        $request->user()->profile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $data,
        );

        return ApiResponse::success('PROFILE_UPDATED', 'Profile updated successfully.', [
            'user' => UserResource::make($request->user()->fresh()->load('profile')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success('LOGGED_OUT', 'Logged out successfully.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success('LOGGED_OUT_ALL_SESSIONS', 'You have been logged out from all sessions.');
    }
}
