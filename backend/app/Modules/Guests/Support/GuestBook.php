<?php

namespace App\Modules\Guests\Support;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\DeliveryService;
use App\Modules\Events\Models\Event;
use App\Modules\Guests\Jobs\SendGuestInvitation;
use App\Modules\Guests\Models\Guest;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Actions\IssueInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Liste des invités d'un événement et leurs réponses. À appeler dans le contexte de l'organisation
 * de l'événement (une réponse d'invité arrive sans contexte : TenantKeys l'ouvre avant).
 *
 * Quand un invité confirme, son invitation avec QR code est émise (commande gratuite du module
 * Ticketing), envoyée par e-mail et, s'il a donné son numéro, sur WhatsApp ; s'il se désiste,
 * elle est annulée et sa place à table libérée.
 */
final class GuestBook
{
    /** Au-delà, la liste ne sert plus une fête de famille : refusé. */
    public const MAX_GUESTS = 2000;

    public function __construct(
        private readonly IssueInvitation $invitations,
        private readonly Seating $seating,
        private readonly DeliveryService $deliveries,
    ) {}

    /**
     * @param  list<array{name: string, phone?: string|null, email?: string|null, group_name?: string|null, companions_allowed?: int}>  $rows
     * @return Collection<int, Guest>
     */
    public function add(Event $event, array $rows, string $locale): Collection
    {
        return DB::transaction(function () use ($event, $rows, $locale) {
            Event::whereKey($event->id)->lockForUpdate()->first(); // deux imports simultanés : le plafond tient
            if (Guest::where('event_id', $event->id)->count() + count($rows) > self::MAX_GUESTS) {
                throw ValidationException::withMessages(['guests' => __('guests.too_many', ['max' => self::MAX_GUESTS])]);
            }

            return collect($rows)->map(function (array $row) use ($event, $locale) {
                $guest = new Guest([...$row, 'locale' => $locale]);
                $guest->event()->associate($event);
                $guest->save();
                TenantKeys::remember($guest->id, $guest->organization_id);

                return $guest;
            });
        });
    }

    /**
     * Modifié par l'organisateur. Moins d'accompagnants permis que d'accompagnants confirmés : la
     * réponse est ramenée au nouveau maximum ; plus de personnes que de places libres à sa table, refusé.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Guest $guest, array $attributes): Guest
    {
        return DB::transaction(function () use ($guest, $attributes) {
            $tables = $this->seating->lockTables($guest->event_id)->keyBy('id');
            $guest = Guest::lockForUpdate()->findOrFail($guest->id);
            $guest->fill($attributes);
            if ($guest->companions_confirmed !== null && $guest->companions_confirmed > $guest->companions_allowed) {
                $guest->companions_confirmed = $guest->companions_allowed;
            }
            if ($guest->table_id && $guest->isDirty('companions_allowed')) {
                $table = $tables[$guest->table_id];
                $left = $table->seats - $this->seating->occupied($table, except: $guest);
                if ($guest->party() > $left) {
                    throw ValidationException::withMessages(['companions_allowed' => __('guests.table_full', ['table' => $table->label(), 'left' => max(0, $left), 'party' => $guest->party()])]);
                }
            }
            $guest->save();

            if ($guest->order_id) {
                $this->invitations->update($guest->order_id, [
                    'name' => $guest->name,
                    'email' => $guest->email,
                    'companions' => (int) $guest->companions_confirmed,
                ]);
            }

            return $guest;
        });
    }

    public function remove(Guest $guest): void
    {
        DB::transaction(function () use ($guest) {
            if ($guest->order_id) {
                $this->invitations->cancel($guest->order_id);
            }
            $guest->delete();
        });
    }

    /**
     * Réponse de l'invité par son lien. Il peut changer d'avis jusqu'au début de l'événement :
     * confirmer de nouveau rend son QR code valable, décliner l'annule et libère sa table.
     */
    public function respond(Guest $guest, bool $attending, int $companions): Guest
    {
        return DB::transaction(function () use ($guest, $attending, $companions) {
            $tables = $this->seating->lockTables($guest->event_id)->keyBy('id');
            $guest = Guest::with('event')->lockForUpdate()->findOrFail($guest->id);
            $event = $guest->event;
            if ($event->starts_at->isPast()) {
                throw ValidationException::withMessages(['attending' => __('guests.closed')]);
            }
            $wasConfirmed = $guest->status === Guest::CONFIRMED;

            if (! $attending) {
                $guest->forceFill(['status' => Guest::DECLINED, 'companions_confirmed' => null, 'table_id' => null, 'responded_at' => now()])->save();
                if ($guest->order_id) {
                    $this->invitations->cancel($guest->order_id);
                }

                return $guest;
            }

            $guest->forceFill([
                'status' => Guest::CONFIRMED,
                'companions_confirmed' => min($companions, $guest->companions_allowed),
                'responded_at' => now(),
            ]);
            if ($guest->table_id) {
                // Plus d'accompagnants qu'à sa première réponse : sa table a pu se remplir entre-temps.
                $table = $tables[$guest->table_id];
                if ($guest->party() > $table->seats - $this->seating->occupied($table, except: $guest)) {
                    $guest->table_id = null;
                }
            }
            $order = $this->invitations->issue($event, [
                'name' => $guest->name,
                'email' => $guest->email,
                'locale' => $guest->locale,
                'companions' => $guest->companions_confirmed,
                'seat' => $guest->table_id ? $tables[$guest->table_id]->label() : null,
            ], $guest->order_id);
            $guest->forceFill(['order_id' => $order->id])->save();

            // Invitation avec QR code aussi sur WhatsApp, au numéro donné par l'organisateur (une fois par confirmation).
            if (! $wasConfirmed && $guest->phone && ChannelRegistry::enabled(Delivery::WHATSAPP)) {
                $this->deliveries->whatsapp($order, $guest->phone);
            }

            return $guest;
        });
    }

    /**
     * Invitations (lien de réponse) envoyées par e-mail aux invités choisis qui ont une adresse
     * et peuvent être relancés (au plus une fois par jour). Rend le nombre d'e-mails partis.
     *
     * @param  Collection<int, Guest>  $guests
     */
    public function email(Collection $guests): int
    {
        $sendable = $guests->filter(fn (Guest $guest) => $guest->email && $guest->canBeReminded());
        foreach ($sendable as $guest) {
            SendGuestInvitation::dispatch($guest->organization_id, $guest->id)->afterCommit();
            $this->markSent($guest);
        }

        return $sendable->count();
    }

    /** Invitation partie, par l'organisateur lui-même (WhatsApp de son téléphone, Messenger…) ou par e-mail. */
    public function markSent(Guest $guest): Guest
    {
        $guest->forceFill(['invited_at' => $guest->invited_at ?? now(), 'reminded_at' => now()])->save();

        return $guest;
    }

    /** @return array<string, int> chiffres du tableau des réponses */
    public static function summary(Collection $guests): array
    {
        $confirmed = $guests->where('status', Guest::CONFIRMED);

        return [
            'guests' => $guests->count(),
            'confirmed' => $confirmed->count(),
            'declined' => $guests->where('status', Guest::DECLINED)->count(),
            'pending' => $guests->where('status', Guest::PENDING)->count(),
            'not_invited' => $guests->whereNull('invited_at')->count(),
            // Personnes attendues : confirmées avec leurs accompagnants, plus tous ceux qui n'ont pas répondu.
            'people_confirmed' => $confirmed->sum(fn (Guest $g) => $g->party()),
            'people_expected' => $guests->sum(fn (Guest $g) => $g->party()),
            'unseated' => $guests->whereNull('table_id')->where('status', '!=', Guest::DECLINED)->count(),
        ];
    }
}
