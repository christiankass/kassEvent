<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Envoi simulé, sans compte Meta (développement, démonstrations, tests) : rien ne part, le contenu
 * est écrit dans le journal et l'envoi est aussitôt « remis ». Un numéro WhatsApp qui finit par
 * 0000 simule un numéro sans WhatsApp. Jamais disponible en production (voir ChannelRegistry).
 */
final class SimulatedChannel implements DeliveryChannel
{
    public function send(Delivery $delivery, TicketMessages $messages): void
    {
        if ($delivery->channel === Delivery::WHATSAPP && str_ends_with((string) $delivery->recipient, config('messaging.simulated_failure_suffix'))) {
            throw new DeliveryFailed('not_on_whatsapp');
        }

        $sent = [];
        foreach ($messages->tickets() as $ticket) {
            $messages->image($ticket); // l'image doit pouvoir être produite, comme pour un vrai envoi
            $sent[$ticket->code] = 'sim-'.Str::uuid();
            Log::info("[{$delivery->channel} simulé] ".$messages->caption($ticket), ['delivery' => $delivery->id]);
        }
        $delivery->messages = $sent;
        $delivery->save();
    }
}
