<?php

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Jobs\DeliverTickets;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Events\TicketsIssued;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Envois des billets par WhatsApp et Messenger, demandés par l'acheteur (à la commande ou depuis
 * sa page de commande). À appeler dans le contexte de l'organisation de la commande.
 *
 * Messenger : une page Facebook ne peut écrire qu'à une personne qui lui a écrit dans les
 * dernières 24 heures. L'acheteur reçoit donc un lien m.me à usage unique ; quand il l'ouvre
 * (« Démarrer » ou message), Meta nous transmet son identifiant avec le lien, et les billets partent.
 */
final class DeliveryService
{
    /**
     * Un envoi en cours depuis moins longtemps n'est pas relancé (double clic, envoi lent) : plus que
     * tous les nouveaux essais d'un job (environ 45 minutes). Au-delà, un envoi bloqué peut être refait ;
     * le nouveau tirage arrête alors les essais restants de l'ancien.
     */
    private const IN_PROGRESS_MINUTES = 60;

    /** Envoi WhatsApp vers ce numéro : tout de suite si les billets sont émis, sinon au paiement. */
    public function whatsapp(Order $order, string $number): Delivery
    {
        $delivery = $this->row($order, Delivery::WHATSAPP);
        $delivery->forceFill(['recipient' => $number, 'status' => Delivery::SCHEDULED, ...$this->reset()])->save();
        $this->queueIfIssued($delivery);

        return $delivery;
    }

    /** Nouveau lien m.me à usage unique (un lien précédent non utilisé ne marche plus). */
    public function messengerLink(Order $order): string
    {
        $delivery = $this->row($order, Delivery::MESSENGER);
        $secret = Str::random(32);
        $delivery->forceFill([
            'recipient' => null,
            'status' => Delivery::AWAITING_CONTACT,
            'link_secret' => hash('sha256', $secret),
            ...$this->reset(),
        ])->save();
        $ref = "{$delivery->id}_{$secret}";

        return ChannelRegistry::mode(Delivery::MESSENGER) === 'simulated'
            ? URL::route('messaging.simulator.messenger', ['ref' => $ref])
            : 'https://m.me/'.config('messaging.messenger.page_username').'?ref='.$ref;
    }

    /** Envoi déjà en cours sur ce canal : inutile (et risqué : billets en double) de le relancer. */
    public function inProgress(Order $order, string $channel): bool
    {
        return Delivery::where('order_id', $order->id)->where('channel', $channel)->where('status', Delivery::QUEUED)
            ->where('updated_at', '>', now()->subMinutes(self::IN_PROGRESS_MINUTES))->exists();
    }

    /**
     * La personne a ouvert la conversation avec la page par le lien de sa commande (notification de
     * Meta, hors contexte). Lien inconnu, déjà utilisé ou falsifié : rien. Rend vrai si l'envoi part.
     */
    public static function linkMessenger(string $ref, string $psid): bool
    {
        if (! preg_match('/^([0-9a-f-]{36})_([A-Za-z0-9]{32})$/', $ref, $m) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $psid)) {
            return false;
        }

        return (bool) TenantKeys::within($m[1], fn () => DB::transaction(function () use ($m, $psid) {
            $delivery = Delivery::lockForUpdate()->find($m[1]);
            if ($delivery?->channel !== Delivery::MESSENGER || $delivery->status !== Delivery::AWAITING_CONTACT
                || ! hash_equals((string) $delivery->link_secret, hash('sha256', $m[2]))) {
                return false;
            }
            $delivery->forceFill(['recipient' => $psid, 'status' => Delivery::SCHEDULED, 'link_secret' => null])->save();

            return app(self::class)->queueIfIssued($delivery);
        }));
    }

    /** Billets émis (module Ticketing) : les envois demandés avant le paiement partent. */
    public function onTicketsIssued(TicketsIssued $event): void
    {
        app(TenantContext::class)->run($event->organizationId, function () use ($event) {
            Delivery::where('order_id', $event->orderId)->where('status', Delivery::SCHEDULED)->get()
                ->each(fn (Delivery $delivery) => $this->queue($delivery));
        });
    }

    /**
     * Accusé de Meta pour un message d'un envoi (WhatsApp : sent, delivered, read, failed).
     * Notification hors contexte : l'identifiant de l'envoi ouvre celui de son organisation.
     */
    public static function recordStatus(string $deliveryId, string $messageId, string $status, ?string $failureCode = null): void
    {
        TenantKeys::within($deliveryId, fn () => DB::transaction(function () use ($deliveryId, $messageId, $status, $failureCode) {
            $delivery = Delivery::lockForUpdate()->find($deliveryId);
            // Accusé d'un envoi précédent (autre numéro) : il ne concerne pas l'envoi actuel.
            if ($delivery === null || ! in_array($messageId, $delivery->messages ?? [], true)) {
                return;
            }
            if ($status === Delivery::FAILED && in_array($delivery->status, [Delivery::QUEUED, Delivery::SENT, Delivery::DELIVERED], true)) {
                $delivery->forceFill(['status' => Delivery::FAILED, 'failure_code' => $failureCode ?? 'failed'])->save();
            } elseif ($delivery->advancesTo($status)) {
                $delivery->forceFill(['status' => $status])->save();
            }
        }));
    }

    /**
     * Vérifie le statut de la commande après avoir enregistré l'envoi : si la confirmation est
     * arrivée entre-temps, son écoute n'a pas vu cette ligne, c'est donc ici qu'on l'envoie.
     */
    private function queueIfIssued(Delivery $delivery): bool
    {
        return Order::whereKey($delivery->order_id)->where('status', Order::PAID)->exists() && $this->queue($delivery);
    }

    /** Une seule mise en file par demande, même si le paiement et la demande arrivent ensemble. */
    private function queue(Delivery $delivery): bool
    {
        $attempt = (string) Str::uuid();
        $queued = Delivery::whereKey($delivery->id)->where('status', Delivery::SCHEDULED)
            ->update(['status' => Delivery::QUEUED, 'attempt' => $attempt, 'updated_at' => now()]) === 1;
        if ($queued) {
            DeliverTickets::dispatch($delivery->organization_id, $delivery->id, $attempt)->afterCommit();
        }

        return $queued;
    }

    private function row(Order $order, string $channel): Delivery
    {
        $delivery = Delivery::createOrFirst(['order_id' => $order->id, 'channel' => $channel], ['status' => Delivery::SCHEDULED]);
        if ($delivery->wasRecentlyCreated) {
            TenantKeys::remember($delivery->id, $delivery->organization_id);
        }

        return $delivery;
    }

    /** @return array<string, null> nouvel envoi : tout repart de zéro */
    private function reset(): array
    {
        return ['messages' => null, 'failure_code' => null, 'attempt' => null];
    }
}
