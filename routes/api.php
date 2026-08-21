<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return ApiResponse::success('API_HEALTHY', 'API is running.', ['status' => 'ok']);
});

Route::prefix('authentication')->middleware('throttle:login')->group(function () {
    Route::post('/otp', [OtpController::class, 'otp'])->middleware('auth.mode:otp');
    Route::post('/login', [OtpController::class, 'login'])->middleware('auth.mode:otp');
    Route::post('/register', [OtpController::class, 'register'])->middleware('auth.mode:otp');
});

Route::prefix('authentication/password')->middleware('auth.mode:password')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('/login', [PasswordController::class, 'login']);
        Route::post('/register', [PasswordController::class, 'register']);
        Route::post('/register/resend', [PasswordController::class, 'resendRegistrationOtp']);
        Route::post('/register/verify', [PasswordController::class, 'verifyRegistration']);
        Route::post('/forgot-password', [PasswordController::class, 'forgotPassword']);
        Route::post('/reset-password', [PasswordController::class, 'resetPassword']);
    });

    Route::patch('/change-password', [PasswordController::class, 'changePassword'])
        ->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthenticatedSessionController::class, 'me']);
    Route::patch('/auth/me/profile', [AuthenticatedSessionController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthenticatedSessionController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthenticatedSessionController::class, 'logoutAll']);
});
