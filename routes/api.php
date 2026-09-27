<?php

use App\Http\Controllers\Admin\Authentication\AdminAuthenticationController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Client\Authentication\AuthenticatedSessionController;
use App\Http\Controllers\Client\Authentication\OtpController;
use App\Http\Controllers\Client\Authentication\PasswordController;
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

Route::post('/admin/authentication/login', [AdminAuthenticationController::class, 'login'])
    ->middleware('throttle:login');

Route::post('/admin/authentication/logout', [AdminAuthenticationController::class, 'logout'])
    ->middleware(['auth:sanctum', 'platform.admin']);

Route::prefix('admin/authentication/2fa')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::post('/setup', [AdminAuthenticationController::class, 'setup']);
    Route::post('/setup/verify', [AdminAuthenticationController::class, 'confirmSetup']);
    Route::post('/disable', [AdminAuthenticationController::class, 'disable']);
});

Route::post('/admin/authentication/2fa/verify', [AdminAuthenticationController::class, 'verify'])
    ->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthenticatedSessionController::class, 'me']);
    Route::patch('/auth/me/profile', [AuthenticatedSessionController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthenticatedSessionController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthenticatedSessionController::class, 'logoutAll']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('/roles', [RbacController::class, 'indexRoles']);
    Route::post('/roles', [RbacController::class, 'storeRole']);
    Route::patch('/roles/{role}', [RbacController::class, 'updateRole']);
    Route::delete('/roles/{role}', [RbacController::class, 'destroyRole']);
    Route::get('/platform-admins', [RbacController::class, 'platformAdmins']);
    Route::post('/platform-admins', [RbacController::class, 'storePlatformAdmin']);
    Route::get('/platform-admins/{user}', [RbacController::class, 'showPlatformAdmin']);
    Route::patch('/platform-admins/{user}', [RbacController::class, 'updatePlatformAdmin']);
    Route::patch('/platform-admins/{user}/role', [RbacController::class, 'assignPlatformAdminRole']);
    Route::delete('/platform-admins/{user}', [RbacController::class, 'removePlatformAdmin']);
});
