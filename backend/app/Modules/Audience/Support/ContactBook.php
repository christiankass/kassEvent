<?php

namespace App\Modules\Audience\Support;

use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Events\TicketsIssued;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carnet de contacts de l'organisation courante (contrat publié du module Audience). Une personne
 * est retrouvée par son e-mail, sinon par son numéro. L'accord ne s'ajoute que si elle l'a coché ;
 * une réponse sans accord ne retire jamais un accord déjà donné (seule la désinscription le retire).
 */
final class ContactBook
{
    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, locale?: ?string}  $person
     * @param  ?string  $kind  ce qui est compté : « order » (achat), « invitation » (première réponse d'un invité) ou rien
     */
    public function remember(array $person, bool $consent, string $source, ?string $kind): ?Contact
    {
        $email = isset($person['email']) && $person['email'] !== '' ? mb_strtolower(trim($person['email'])) : null;
        $phone = $person['phone'] ?? null ?: null;
        if ($email === null && $phone === null) {
            return null; // personne à recontacter
        }

        try {
            return $this->save($person, $email, $phone, $consent, $source, $kind);
        } catch (UniqueConstraintViolationException) {
            // Même personne enregistrée au même instant par une autre requête : on la complète.
            return $this->save($person, $email, $phone, $consent, $source, $kind);
        }
    }

    /** Une commande de la page publique dont les billets viennent d'être émis : son acheteur entre au carnet. */
    public function onTicketsIssued(TicketsIssued $event): void
    {
        app(TenantContext::class)->run($event->organizationId, function () use ($event) {
            $order = Order::find($event->orderId);
            if ($order === null || $order->source !== Order::SALE) {
                return;
            }
            $this->remember(
                ['name' => $order->buyer_name, 'email' => $order->buyer_email, 'phone' => $order->buyer_phone, 'locale' => $order->locale],
                $order->marketing_consent, Contact::CHECKOUT, 'order',
            );
        });
    }

    public function unsubscribe(Contact $contact): void
    {
        if ($contact->unsubscribed_at === null) {
            $contact->forceFill(['unsubscribed_at' => now()])->save();
        }
    }

    private function save(array $person, ?string $email, ?string $phone, bool $consent, string $source, ?string $kind): Contact
    {
        return DB::transaction(function () use ($person, $email, $phone, $consent, $source, $kind) {
            $contact = ($email !== null ? Contact::where('email', $email)->lockForUpdate()->first() : null)
                ?? ($phone !== null ? Contact::where('phone', $phone)->lockForUpdate()->first() : null)
                ?? new Contact(['unsubscribe_key' => Str::random(40)]);

            $contact->name = Str::limit(trim($person['name']), 120, '');
            $contact->locale = $person['locale'] ?? $contact->locale ?? 'fr';
            $contact->email ??= $email;
            // Numéro ajouté seulement s'il n'appartient pas déjà à un autre contact.
            if ($contact->phone === null && $phone !== null && ! Contact::where('phone', $phone)->whereKeyNot($contact->id)->exists()) {
                $contact->phone = $phone;
            }
            if ($consent) {
                $contact->forceFill(['marketing_consent' => true, 'consent_source' => $source, 'consented_at' => now(), 'unsubscribed_at' => null]);
            }
            $counter = ['order' => 'orders_count', 'invitation' => 'invitations_count'][$kind] ?? null;
            if ($counter !== null) {
                $contact->forceFill([$counter => (int) $contact->{$counter} + 1]);
            }
            $contact->last_activity_at = now();
            $isNew = ! $contact->exists;
            $contact->save();
            if ($isNew) {
                TenantKeys::remember($contact->id, $contact->organization_id);
            }

            return $contact;
        });
    }
}
