<?php

use App\Modules\Access\Http\Controllers\InvitationController;
use App\Modules\Access\Http\Controllers\InvitationLinkController;
use App\Modules\Access\Http\Controllers\MemberController;
use App\Modules\Access\Http\Middleware\RequireTwoFactorForMoney;
use App\Modules\CheckIn\Http\Controllers\CheckInController;
use App\Modules\Events\Http\Controllers\EventController;
use App\Modules\Events\Http\Controllers\EventDesignController;
use App\Modules\Events\Http\Controllers\PublicEventController;
use App\Modules\Events\Http\Controllers\StudioController;
use App\Modules\Events\Http\Controllers\TicketTypeController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use App\Modules\Identity\Http\Middleware\RequireSession;
use App\Modules\Payments\Http\Controllers\WebhookController;
use App\Modules\Payouts\Http\Controllers\PayoutAccountController;
use App\Modules\Payouts\Http\Controllers\PayoutController;
use App\Modules\Payouts\Http\Controllers\PayoutWebhookController;
use App\Modules\Tenancy\Http\Controllers\OrganizationController;
use App\Modules\Ticketing\Http\Controllers\PublicOrderController;
use App\Modules\Ticketing\Http\Controllers\SalesController;
use App\Modules\Ticketing\Http\Controllers\TicketPreviewController;
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

    // Page de vente publique d'un événement publié.
    Route::get('/public/events/{slug}', [PublicEventController::class, 'show'])
        ->middleware('throttle:public')->where('slug', '[a-z0-9-]{1,100}')->name('public.events.show');

    // Achat sans compte, puis suivi de la commande par le lien personnel de l'acheteur.
    Route::post('/public/events/{slug}/orders', [PublicOrderController::class, 'store'])
        ->middleware('throttle:checkout')->where('slug', '[a-z0-9-]{1,100}')->name('public.orders.store');
    Route::get('/public/orders/{id}', [PublicOrderController::class, 'show'])
        ->middleware('throttle:order')->whereUuid('id')->name('public.orders.show');
    Route::get('/public/orders/{id}/tickets/{code}', [PublicOrderController::class, 'ticketImage'])
        ->middleware('throttle:order-tickets')->whereUuid('id')->where('code', '[0-9A-Z-]{12,14}')->name('public.orders.ticket-image');
    Route::post('/public/orders/{id}/payments', [PublicOrderController::class, 'pay'])
        ->middleware('throttle:checkout')->whereUuid('id')->name('public.orders.pay');

    // Notifications des prestataires de paiement (adresses à déclarer chez pawaPay et Stripe).
    Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:webhooks')->group(function () {
        Route::post('/pawapay', [WebhookController::class, 'pawapay'])->name('pawapay');
        Route::post('/pawapay/payouts', PayoutWebhookController::class)->name('pawapay.payouts');
        Route::post('/stripe', [WebhookController::class, 'stripe'])->name('stripe');
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

            Route::middleware('can:events.view')->group(function () {
                Route::get('/events', [EventController::class, 'index'])->name('events.index');
                Route::get('/events/{id}', [EventController::class, 'show'])->whereUuid('id')->name('events.show');
                Route::get('/events/{id}/design', [EventDesignController::class, 'show'])->whereUuid('id')->name('events.design.show');
                Route::get('/events/{id}/design/preview', [TicketPreviewController::class, 'show'])->whereUuid('id')->name('events.design.preview');

                // Studio (éditeur libre) : document, images et billet d'exemple.
                Route::get('/events/{id}/studio', [StudioController::class, 'show'])->whereUuid('id')->name('events.studio.show');
                Route::get('/events/{id}/studio/assets/{name}', [StudioController::class, 'asset'])
                    ->whereUuid('id')->where('name', 'cover|[A-Za-z0-9]{40}\.(png|jpg)')->name('events.studio.asset');
                Route::get('/events/{id}/studio/preview', [TicketPreviewController::class, 'canvas'])->whereUuid('id')->name('events.studio.preview');
            });

            Route::middleware('can:events.manage')->group(function () {
                Route::post('/events', [EventController::class, 'store'])->name('events.store');
                Route::patch('/events/{id}', [EventController::class, 'update'])->whereUuid('id')->name('events.update');
                Route::delete('/events/{id}', [EventController::class, 'destroy'])->whereUuid('id')->name('events.destroy');
                Route::post('/events/{id}/publish', [EventController::class, 'publish'])->whereUuid('id')->name('events.publish');
                Route::post('/events/{id}/unpublish', [EventController::class, 'unpublish'])->whereUuid('id')->name('events.unpublish');
                Route::post('/events/{id}/cover', [EventController::class, 'cover'])->whereUuid('id')->name('events.cover');

                // Atelier : apparence des billets et invitations.
                Route::put('/events/{id}/design', [EventDesignController::class, 'update'])->whereUuid('id')->name('events.design.update');
                Route::post('/events/{id}/design/{image}', [EventDesignController::class, 'upload'])
                    ->whereUuid('id')->whereIn('image', ['logo', 'banner'])->name('events.design.upload');
                Route::delete('/events/{id}/design/{image}', [EventDesignController::class, 'removeImage'])
                    ->whereUuid('id')->whereIn('image', ['logo', 'banner'])->name('events.design.remove-image');
                Route::post('/events/{id}/design/test', [TicketPreviewController::class, 'send'])
                    ->middleware('throttle:design-test')->whereUuid('id')->name('events.design.test');

                Route::post('/events/{id}/studio/assets', [StudioController::class, 'storeAsset'])->whereUuid('id')->name('events.studio.assets.store');
                Route::post('/events/{id}/studio/ticket', [StudioController::class, 'saveTicket'])->whereUuid('id')->name('events.studio.ticket');
                Route::post('/events/{id}/studio/poster', [StudioController::class, 'savePoster'])->whereUuid('id')->name('events.studio.poster');
                Route::post('/events/{id}/studio/classic', [StudioController::class, 'useClassic'])->whereUuid('id')->name('events.studio.classic');

                Route::post('/events/{id}/ticket-types', [TicketTypeController::class, 'store'])->whereUuid('id')->name('ticket-types.store');
                Route::patch('/ticket-types/{id}', [TicketTypeController::class, 'update'])->whereUuid('id')->name('ticket-types.update');
                Route::delete('/ticket-types/{id}', [TicketTypeController::class, 'destroy'])->whereUuid('id')->name('ticket-types.destroy');
            });

            // Contrôle d'entrée : liste pour le hors ligne, puis scans (un par un en ligne, par lot au retour du réseau).
            Route::middleware('can:checkin.scan')->group(function () {
                Route::get('/events/{id}/check-in', [CheckInController::class, 'show'])->whereUuid('id')->name('events.check-in.show');
                Route::post('/events/{id}/check-in/scans', [CheckInController::class, 'scans'])
                    ->middleware('throttle:scans')->whereUuid('id')->name('events.check-in.scans');
            });

            // Ventes : double authentification obligatoire pour les rôles qui touchent à l'argent.
            Route::middleware(['can:orders.view', RequireTwoFactorForMoney::class])->group(function () {
                Route::get('/events/{id}/orders', [SalesController::class, 'index'])->whereUuid('id')->name('events.orders.index');
            });

            // Compte de perception et reversements, sous la même protection.
            Route::middleware(['can:payouts.view', RequireTwoFactorForMoney::class])->group(function () {
                Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');

                Route::middleware('can:payouts.manage')->group(function () {
                    Route::post('/payouts', [PayoutController::class, 'store'])->middleware('throttle:payouts')->name('payouts.store');
                    Route::put('/payouts/account', [PayoutAccountController::class, 'update'])->middleware('throttle:payouts')->name('payouts.account.update');
                });
            });

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
