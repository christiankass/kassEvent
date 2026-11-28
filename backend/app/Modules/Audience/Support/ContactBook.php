<?php

namespace App\Modules\Audience\Support;

use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Events\TicketsIssued;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Comme remember(), sans jamais faire échouer l'action principale (achat, réponse d'un invité) :
     * une erreur du carnet est seulement signalée.
     */
    public function rememberQuietly(array $person, bool $consent, string $source, ?string $kind): void
    {
        rescue(fn () => $this->remember($person, $consent, $source, $kind));
    }

    /** Une commande de la page publique dont les billets viennent d'être émis : son acheteur entre au carnet. */
    public function onTicketsIssued(TicketsIssued $event): void
    {
        if ($event->buyer === null) {
            return; // invitation, commande saisie au guichet…
        }
        app(TenantContext::class)->run($event->organizationId, fn () => $this->rememberQuietly(
            $event->buyer, $event->buyer['marketing_consent'], Contact::CHECKOUT, 'order',
        ));
    }

    /** Canal d'une campagne => colonne de coordonnée nécessaire. */
    private const CHANNEL_FIELDS = ['email' => 'email', 'whatsapp' => 'phone'];

    /** Colonne de coordonnée qu'un canal de campagne utilise (email ou phone). */
    public static function field(string $channel): string
    {
        return self::CHANNEL_FIELDS[$channel];
    }

    /**
     * Contacts qu'une campagne peut écrire sur ce canal (e-mail ou WhatsApp) : joignables, avec la
     * coordonnée du canal, parmi tous, les acheteurs ou les invités.
     *
     * @return Builder<Contact>
     */
    public function audience(string $channel, string $audience): Builder
    {
        return Contact::query()->reachable()->whereNotNull(self::CHANNEL_FIELDS[$channel])
            ->when($audience === 'buyers', fn (Builder $query) => $query->where('orders_count', '>', 0))
            ->when($audience === 'guests', fn (Builder $query) => $query->where('invitations_count', '>', 0));
    }

    /** @return array<string, array{all: int, buyers: int, guests: int}> contacts joignables par canal et par public */
    public function audienceCounts(): array
    {
        $filters = [];
        foreach (self::CHANNEL_FIELDS as $channel => $field) {
            $filters[] = "count({$field}) as {$channel}_all, count({$field}) filter (where orders_count > 0) as {$channel}_buyers, count({$field}) filter (where invitations_count > 0) as {$channel}_guests";
        }
        $row = (array) Contact::query()->reachable()->toBase()->selectRaw(implode(', ', $filters))->first();

        return collect(self::CHANNEL_FIELDS)->map(fn ($field, $channel) => [
            'all' => (int) $row["{$channel}_all"], 'buyers' => (int) $row["{$channel}_buyers"], 'guests' => (int) $row["{$channel}_guests"],
        ])->all();
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
                // Un numéro partagé (famille, bureau) ne fusionne pas deux personnes aux e-mails différents.
                ?? ($phone !== null ? Contact::where('phone', $phone)->when($email !== null, fn ($q) => $q->whereNull('email'))->lockForUpdate()->first() : null)
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
