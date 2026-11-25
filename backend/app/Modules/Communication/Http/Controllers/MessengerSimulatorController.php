<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\DeliveryService;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lien Messenger en mode simulé (jamais en production) : fait comme si la personne avait ouvert
 * la conversation avec la page, puis la ramène sur sa commande.
 */
final class MessengerSimulatorController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $ref = (string) $request->query('ref');
        abort_unless(DeliveryService::linkMessenger($ref, 'sim-'.Str::lower(Str::random(16))), 404);

        $url = TenantKeys::within(Str::before($ref, '_'), fn () => Delivery::with('order')->find(Str::before($ref, '_'))?->order->publicUrl());

        return redirect()->away($url ?? config('app.frontend_url'));
    }
}
