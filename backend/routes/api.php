<?php

use App\Modules\Access\Http\Controllers\InvitationController;
use App\Modules\Access\Http\Controllers\InvitationLinkController;
use App\Modules\Access\Http\Controllers\MemberController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
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
            Route::post('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        });
        Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    });

    // Lien d'invitation reçu par e-mail : consultable sans compte ; l'accepter demande d'être connecté.
    Route::prefix('invitation')->name('invitation.')->group(function () {
        Route::get('/', [InvitationLinkController::class, 'show'])->middleware('throttle:invitation')->name('show');
        Route::middleware('throttle:auth')->group(function () {
            Route::post('/renew', [InvitationLinkController::class, 'renew'])->name('renew');
            Route::post('/accept', [InvitationLinkController::class, 'accept'])
                ->middleware(['auth:sanctum', 'tenant.user'])->name('accept');
        });
    });

    Route::middleware(['auth:sanctum', 'tenant.user'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::prefix('auth/two-factor')->name('auth.two-factor.')->middleware('throttle:auth')->group(function () {
            Route::post('/', [TwoFactorController::class, 'enable'])->name('enable');
            Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
            Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
            Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
        });

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');

        // Tout ce qui suit agit dans une organisation : en-tête X-Organization-Id obligatoire.
        Route::middleware('tenant')->group(function () {
            Route::get('/organization', [OrganizationController::class, 'current'])->name('organization.current');

            Route::middleware('can:members.manage')->group(function () {
                Route::get('/members', [MemberController::class, 'index'])->name('members.index');
                Route::patch('/members/{id}', [MemberController::class, 'update'])->name('members.update')->whereUuid('id');
                Route::delete('/members/{id}', [MemberController::class, 'destroy'])->name('members.destroy')->whereUuid('id');

                Route::get('/invitations', [InvitationController::class, 'index'])->name('invitations.index');
                Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
                Route::post('/invitations/{id}/resend', [InvitationController::class, 'resend'])->name('invitations.resend')->whereUuid('id');
                Route::delete('/invitations/{id}', [InvitationController::class, 'destroy'])->name('invitations.destroy')->whereUuid('id');
            });
        });
    });
});
