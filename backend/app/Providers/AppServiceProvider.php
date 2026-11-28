<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Audience\Support\ContactBook;
use App\Modules\Campaigns\Console\DispatchCampaigns;
use App\Modules\Communication\Support\DeliveryService;
use App\Modules\Contributions\Actions\ConfirmContribution;
use App\Modules\Contributions\Events\ContributionPaid;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payouts\Console\CompletePayout;
use App\Modules\Payouts\Console\ListBankTransfers;
use App\Modules\Payouts\Console\RunPayouts;
use App\Modules\Payouts\Support\PayoutService;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Actions\ConfirmOrder;
use App\Modules\Ticketing\Events\OrderPaid;
use App\Modules\Ticketing\Events\TicketsIssued;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Une instance par requête ou par job : jamais d'organisation qui fuit d'un appel à l'autre.
        $this->app->scoped(TenantContext::class);

        // Vérification des mots de passe divulgués (production) : ne jamais bloquer une inscription plus de 3 s.
        $this->app->singleton(UncompromisedVerifier::class, fn ($app) => new NotPwnedVerifier($app[HttpFactory::class], timeout: 3));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10)->letters()->numbers()
            ->when(app()->isProduction(), fn (Password $rule) => $rule->uncompromised()));

        // Le lien de l'e-mail ouvre la page du site, pas l'API.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('app.frontend_url')
            .'/mot-de-passe/reinitialiser?'.http_build_query(['token' => $token, 'email' => $user->email]));

        // Inscription, connexion et mot de passe oublié : 10 requêtes par minute et par adresse IP.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Consultation d'un lien d'invitation : plus large, plusieurs invités peuvent partager une adresse IP.
        RateLimiter::for('invitation', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        // Page publique : rendue par le serveur Next.js, dont toutes les requêtes partagent une adresse.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));
        // Visites de la page publique : une par session et par événement, mais un réseau mobile partage
        // souvent une adresse IP entre beaucoup de visiteurs.
        RateLimiter::for('visits', fn (Request $request) => Limit::perMinute(30)->by($request->ip().'|'.$request->route('slug')));
        // Commandes et paiements : un réseau mobile partage souvent une adresse IP entre beaucoup d'acheteurs.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // Page de commande, consultée toutes les quelques secondes pendant un paiement mobile money.
        RateLimiter::for('order', fn (Request $request) => Limit::perMinute(60)->by($request->ip().'|'.$request->route('id')));
        // Images des billets d'une commande : une par billet (jusqu'à plusieurs centaines), en plus des consultations.
        RateLimiter::for('order-tickets', fn (Request $request) => Limit::perMinute(1200)->by($request->ip().'|'.$request->route('id')));
        // E-mail d'exemple de l'atelier : envoyé à soi-même, quelques fois seulement.
        RateLimiter::for('design-test', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));
        // Banque de photos du studio : la galerie demande un thème par groupe de modèles affichés.
        RateLimiter::for('stock-photos', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        // Copie d'une photo de la banque : quelques-unes par modèle choisi.
        RateLimiter::for('stock-imports', fn (Request $request) => Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()));
        // Une entrée qui se presse : un scan par seconde et par agent, et la synchro des lots hors ligne.
        RateLimiter::for('scans', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        // Compte de reversement (code de double authentification) et « Reverser maintenant ».
        RateLimiter::for('payouts', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        // Envoi des billets sur WhatsApp, Messenger ou de nouveau par e-mail : chaque envoi WhatsApp est payant.
        RateLimiter::for('deliveries', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip().'|'.$request->route('id')),
            Limit::perDay(10)->by('order|'.$request->route('id')),
        ]);
        // Page de réponse d'un invité ; sa réponse (il peut changer d'avis, sans abus).
        RateLimiter::for('rsvp', fn (Request $request) => Limit::perMinute(60)->by($request->ip().'|'.$request->route('id')));
        RateLimiter::for('rsvp-answer', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip().'|'.$request->route('id')),
            Limit::perDay(30)->by('guest|'.$request->route('id')),
        ]);
        // Vœux : une personne écrit un ou deux messages, mais un réseau mobile partage une adresse entre beaucoup de proches.
        RateLimiter::for('wishes', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip().'|'.$request->route('id')),
            Limit::perHour(60)->by($request->ip().'|'.$request->route('id')),
            Limit::perDay(1000)->by('wish-book|'.$request->route('id')),
        ]);
        // Une campagne écrit à tout le carnet : quelques départs par heure suffisent.
        RateLimiter::for('campaign-sends', fn (Request $request) => Limit::perHour(10)->by($request->user()?->id ?: $request->ip()));
        // Invitations par e-mail : un envoi groupé à la fois, quelques fois par heure.
        RateLimiter::for('guest-emails', fn (Request $request) => Limit::perHour(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Un paiement réussi (module Payments) confirme la commande et émet les billets (module Ticketing).
        Event::listen(PaymentSucceeded::class, [ConfirmOrder::class, 'onPaymentSucceeded']);
        // Une commande payée (module Ticketing) est inscrite au compte de perception (module Payouts).
        Event::listen(OrderPaid::class, [PayoutService::class, 'onOrderPaid']);
        // Une contribution payée (livre d'or) est inscrite au compte de perception, sans commission.
        Event::listen(PaymentSucceeded::class, [ConfirmContribution::class, 'onPaymentSucceeded']);
        Event::listen(ContributionPaid::class, [PayoutService::class, 'onContributionPaid']);
        // Billets émis (module Ticketing) : envoi WhatsApp ou Messenger demandé par l'acheteur (module Communication).
        Event::listen(TicketsIssued::class, [DeliveryService::class, 'onTicketsIssued']);
        // L'acheteur entre au carnet de contacts de l'organisation (module Audience).
        Event::listen(TicketsIssued::class, [ContactBook::class, 'onTicketsIssued']);

        if ($this->app->runningInConsole()) {
            $this->commands([RunPayouts::class, ListBankTransfers::class, CompletePayout::class, DispatchCampaigns::class]);
        }

        // Les workers gardent leur connexion PostgreSQL ouverte : chaque job repart d'un contexte vide,
        // quelle qu'ait été l'issue du précédent. (En file « sync », le job tourne dans la requête
        // et doit garder son contexte.)
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync' && array_key_exists('pgsql', DB::getConnections())) {
                app(TenantContext::class)->clear();
            }
        });
    }
}
