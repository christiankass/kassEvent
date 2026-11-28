<?php

use App\Modules\Access\Http\Controllers\InvitationController;
use App\Modules\Access\Http\Controllers\InvitationLinkController;
use App\Modules\Access\Http\Controllers\MemberController;
use App\Modules\Access\Http\Middleware\RequireTwoFactorForMoney;
use App\Modules\Analytics\Http\Controllers\EventStatsController;
use App\Modules\Analytics\Http\Controllers\PublicVisitController;
use App\Modules\Audience\Http\Controllers\ContactController;
use App\Modules\Audience\Http\Controllers\PublicUnsubscribeController;
use App\Modules\CheckIn\Http\Controllers\CheckInController;
use App\Modules\Communication\Http\Controllers\MetaWebhookController;
use App\Modules\Contributions\Http\Controllers\ContributionController;
use App\Modules\Contributions\Http\Controllers\PublicContributionController;
use App\Modules\Events\Http\Controllers\EventController;
use App\Modules\Events\Http\Controllers\EventDesignController;
use App\Modules\Events\Http\Controllers\PublicEventController;
use App\Modules\Events\Http\Controllers\PublicWishController;
use App\Modules\Events\Http\Controllers\StudioController;
use App\Modules\Events\Http\Controllers\TicketTypeController;
use App\Modules\Events\Http\Controllers\WishController;
use App\Modules\Guests\Http\Controllers\GuestController;
use App\Modules\Guests\Http\Controllers\PublicGuestController;
use App\Modules\Guests\Http\Controllers\SeatingController;
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

    // Visite de la page publique (une par session), avec la source du lien partagé.
    Route::post('/public/events/{slug}/visits', PublicVisitController::class)
        ->middleware('throttle:visits')->where('slug', '[a-z0-9-]{1,100}')->name('public.events.visits');

    // Achat sans compte, puis suivi de la commande par le lien personnel de l'acheteur.
    Route::post('/public/events/{slug}/orders', [PublicOrderController::class, 'store'])
        ->middleware('throttle:checkout')->where('slug', '[a-z0-9-]{1,100}')->name('public.orders.store');
    Route::get('/public/orders/{id}', [PublicOrderController::class, 'show'])
        ->middleware('throttle:order')->whereUuid('id')->name('public.orders.show');
    Route::get('/public/orders/{id}/tickets/{code}', [PublicOrderController::class, 'ticketImage'])
        ->middleware('throttle:order-tickets')->whereUuid('id')->where('code', '[0-9A-Z-]{12,14}')->name('public.orders.ticket-image');
    // Envoi des billets sur WhatsApp ou Messenger, ou nouvel envoi par e-mail, depuis la page de commande.
    Route::post('/public/orders/{id}/deliveries', [PublicOrderController::class, 'deliver'])
        ->middleware('throttle:deliveries')->whereUuid('id')->name('public.orders.deliver');
    Route::post('/public/orders/{id}/payments', [PublicOrderController::class, 'pay'])
        ->middleware('throttle:checkout')->whereUuid('id')->name('public.orders.pay');

    // Réponse d'un invité (événement privé) par son lien personnel, sans compte.
    Route::get('/public/guests/{id}', [PublicGuestController::class, 'show'])
        ->middleware('throttle:rsvp')->whereUuid('id')->name('public.guests.show');
    // Désinscription d'un contact par son lien personnel (campagnes de l'organisation).
    Route::get('/public/contacts/{id}/unsubscribe', [PublicUnsubscribeController::class, 'show'])
        ->middleware('throttle:rsvp')->whereUuid('id')->name('public.contacts.unsubscribe.show');
    Route::post('/public/contacts/{id}/unsubscribe', [PublicUnsubscribeController::class, 'store'])
        ->middleware('throttle:rsvp')->whereUuid('id')->name('public.contacts.unsubscribe');
    Route::post('/public/guests/{id}/rsvp', [PublicGuestController::class, 'respond'])
        ->middleware('throttle:rsvp-answer')->whereUuid('id')->name('public.guests.rsvp');

    // Livre de vœux (anniversaire, mariage) : page publique par son lien, message sans compte.
    Route::get('/public/wish-books/{id}', [PublicWishController::class, 'show'])
        ->middleware('throttle:public')->whereUuid('id')->name('public.wish-books.show');
    Route::post('/public/wish-books/{id}/wishes', [PublicWishController::class, 'store'])
        ->middleware('throttle:wishes')->whereUuid('id')->name('public.wish-books.wishes.store');
    // Contribution (soutien, cadeau) depuis la page du livre, puis suivi du paiement par le lien personnel.
    Route::post('/public/wish-books/{id}/contributions', [PublicContributionController::class, 'store'])
        ->middleware('throttle:checkout')->whereUuid('id')->name('public.contributions.store');
    Route::get('/public/contributions/{id}', [PublicContributionController::class, 'show'])
        ->middleware('throttle:order')->whereUuid('id')->name('public.contributions.show');
    Route::post('/public/contributions/{id}/payments', [PublicContributionController::class, 'pay'])
        ->middleware('throttle:checkout')->whereUuid('id')->name('public.contributions.pay');

    // Notifications des prestataires de paiement (adresses à déclarer chez pawaPay et Stripe).
    Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:webhooks')->group(function () {
        Route::post('/pawapay', [WebhookController::class, 'pawapay'])->name('pawapay');
        Route::post('/pawapay/payouts', PayoutWebhookController::class)->name('pawapay.payouts');
        Route::post('/stripe', [WebhookController::class, 'stripe'])->name('stripe');
        // WhatsApp (accusés) et Messenger (personne arrivée par le lien m.me d'une commande).
        Route::get('/meta', [MetaWebhookController::class, 'verify'])->name('meta.verify');
        Route::post('/meta', [MetaWebhookController::class, 'receive'])->name('meta');
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

                // Invités, réponses et plan de salle.
                Route::get('/events/{id}/guests', [GuestController::class, 'index'])->whereUuid('id')->name('events.guests.index');

                // Livre de vœux.
                Route::get('/events/{id}/wishes', [WishController::class, 'index'])->whereUuid('id')->name('events.wishes.index');
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
                // Banque de photos libres : recherche, puis copie de la photo choisie dans l'événement.
                Route::get('/stock-photos', [StudioController::class, 'stockPhotos'])->middleware('throttle:stock-photos')->name('stock-photos.index');
                Route::post('/events/{id}/studio/assets/stock', [StudioController::class, 'storeStockAsset'])
                    ->middleware('throttle:stock-imports')->whereUuid('id')->name('events.studio.assets.stock');
                Route::post('/events/{id}/studio/ticket', [StudioController::class, 'saveTicket'])->whereUuid('id')->name('events.studio.ticket');
                Route::post('/events/{id}/studio/poster', [StudioController::class, 'savePoster'])->whereUuid('id')->name('events.studio.poster');
                Route::post('/events/{id}/studio/classic', [StudioController::class, 'useClassic'])->whereUuid('id')->name('events.studio.classic');

                // Invités : ajout (à la main ou import), envoi des invitations.
                Route::post('/events/{id}/guests', [GuestController::class, 'store'])->whereUuid('id')->name('events.guests.store');
                Route::post('/events/{id}/guests/email', [GuestController::class, 'email'])
                    ->middleware('throttle:guest-emails')->whereUuid('id')->name('events.guests.email');
                Route::patch('/guests/{id}', [GuestController::class, 'update'])->whereUuid('id')->name('guests.update');
                Route::delete('/guests/{id}', [GuestController::class, 'destroy'])->whereUuid('id')->name('guests.destroy');
                Route::post('/guests/{id}/sent', [GuestController::class, 'sent'])->whereUuid('id')->name('guests.sent');

                // Plan de salle : capacité (tables calculées), tables, placement des invités.
                Route::put('/events/{id}/seating', [SeatingController::class, 'plan'])->whereUuid('id')->name('events.seating.plan');
                Route::post('/events/{id}/seating/auto', [SeatingController::class, 'auto'])->whereUuid('id')->name('events.seating.auto');
                Route::post('/events/{id}/tables', [SeatingController::class, 'storeTable'])->whereUuid('id')->name('events.tables.store');
                Route::patch('/tables/{id}', [SeatingController::class, 'updateTable'])->whereUuid('id')->name('tables.update');
                Route::delete('/tables/{id}', [SeatingController::class, 'destroyTable'])->whereUuid('id')->name('tables.destroy');
                Route::put('/guests/{id}/table', [SeatingController::class, 'assign'])->whereUuid('id')->name('guests.table');

                // Livre de vœux : ouverture et réglages, modération.
                Route::put('/events/{id}/wishes/settings', [WishController::class, 'settings'])->whereUuid('id')->name('events.wishes.settings');
                Route::patch('/wishes/{id}', [WishController::class, 'update'])->whereUuid('id')->name('wishes.update');
                Route::delete('/wishes/{id}', [WishController::class, 'destroy'])->whereUuid('id')->name('wishes.destroy');

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
                // Carnet de contacts : acheteurs et invités, comme la liste des ventes.
                Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
                Route::delete('/contacts/{id}', [ContactController::class, 'destroy'])->middleware('can:events.manage')->whereUuid('id')->name('contacts.destroy');
            });

            // Statistiques et export comptable : montants encaissés, donc double authentification pour les rôles argent.
            Route::middleware(['can:reports.view', RequireTwoFactorForMoney::class])->group(function () {
                Route::get('/events/{id}/stats', [EventStatsController::class, 'show'])->whereUuid('id')->name('events.stats.show');
                Route::get('/events/{id}/stats/export', [EventStatsController::class, 'export'])->whereUuid('id')->name('events.stats.export');
            });

            // Compte de perception et reversements, sous la même protection.
            Route::middleware(['can:payouts.view', RequireTwoFactorForMoney::class])->group(function () {
                Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
                // Contributions reçues sur le livre d'or d'un événement.
                Route::get('/events/{id}/contributions', [ContributionController::class, 'index'])->whereUuid('id')->name('events.contributions.index');

                Route::middleware('can:payouts.manage')->group(function () {
                    Route::post('/payouts', [PayoutController::class, 'store'])->middleware('throttle:payouts')->name('payouts.store');
                    Route::put('/payouts/account', [PayoutAccountController::class, 'update'])->middleware('throttle:payouts')->name('payouts.account.update');
                    Route::put('/events/{id}/contributions/settings', [ContributionController::class, 'settings'])
                        ->middleware('throttle:payouts')->whereUuid('id')->name('events.contributions.settings');
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
