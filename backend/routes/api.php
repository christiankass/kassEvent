<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Middleware\RequireSession;
use App\Modules\Tenancy\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'kassevent-api',
        'version' => 'v1',
    ]))->name('health');

    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::middleware(RequireSession::class)->group(function () {
            Route::post('/register', [AuthController::class, 'register'])->name('register');
            Route::post('/login', [AuthController::class, 'login'])->name('login');
        });
        Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    });

    Route::middleware(['auth:sanctum', 'tenant.user'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');

        // Tout ce qui suit agit dans une organisation : en-tête X-Organization-Id obligatoire.
        Route::middleware('tenant')->group(function () {
            Route::get('/organization', [OrganizationController::class, 'current'])->name('organization.current');
        });
    });
});
