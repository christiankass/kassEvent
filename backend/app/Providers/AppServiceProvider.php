<?php

namespace App\Providers;

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Une instance par requête ou par job : jamais d'organisation qui fuit d'un appel à l'autre.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
