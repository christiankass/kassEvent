<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Actions\ConfirmOrder;
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
        // Commandes et paiements : un réseau mobile partage souvent une adresse IP entre beaucoup d'acheteurs.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // Page de commande, consultée toutes les quelques secondes pendant un paiement mobile money.
        RateLimiter::for('order', fn (Request $request) => Limit::perMinute(60)->by($request->ip().'|'.$request->route('id')));
        // Images des billets d'une commande : une par billet (jusqu'à plusieurs centaines), en plus des consultations.
        RateLimiter::for('order-tickets', fn (Request $request) => Limit::perMinute(1200)->by($request->ip().'|'.$request->route('id')));
        // E-mail d'exemple de l'atelier : envoyé à soi-même, quelques fois seulement.
        RateLimiter::for('design-test', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Un paiement réussi (module Payments) confirme la commande et émet les billets (module Ticketing).
        Event::listen(PaymentSucceeded::class, [ConfirmOrder::class, 'onPaymentSucceeded']);

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
