<?php

namespace App\Modules\Ticketing\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\Inventory;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Réserve les places d'une commande. À appeler dans le contexte de l'organisation de l'événement.
 * Les types de billets sont verrouillés pendant le calcul : deux acheteurs simultanés ne peuvent
 * pas prendre ensemble plus de places qu'il n'en reste.
 * Avec une clé d'idempotence, un même envoi répété (double clic, réseau qui renvoie la demande)
 * rend la commande déjà créée au lieu d'en créer une seconde : wasRecentlyCreated est alors faux.
 */
final class CreateOrder
{
    public function __construct(private readonly ConfirmOrder $confirm) {}

    /**
     * @param  array<string, int>  $quantities  identifiant du type de billet => quantité (> 0)
     * @param  array{name: string, email: string, phone?: ?string, marketing_consent?: bool}  $buyer
     * @param  bool  $hasPayment  un moyen de paiement est choisi (obligatoire si la commande n'est pas gratuite)
     * @param  string|null  $idempotencyKey  identifiant tiré au hasard par la page d'achat pour cet envoi
     * @param  string|null  $referral  source de la visite (statistiques, module Analytics)
     */
    public function handle(Event $event, array $quantities, array $buyer, string $locale, bool $hasPayment, ?string $idempotencyKey = null, ?string $referral = null): Order
    {
        if ($event->starts_at->isPast()) {
            throw ValidationException::withMessages(['items' => __('ticketing.event_started')]);
        }

        try {
            $order = $this->create($event, $quantities, $buyer, $locale, $hasPayment, $idempotencyKey, $referral);
        } catch (UniqueConstraintViolationException $e) {
            // Même envoi arrivé en même temps pour d'autres billets : l'autre demande a créé la commande.
            $order = $idempotencyKey !== null ? $this->existing($event, $idempotencyKey) : null;
            if ($order === null) {
                throw $e;
            }
        }

        // Billets gratuits sur inscription : rien à payer, les billets partent tout de suite.
        // Aussi sur un envoi répété : si la confirmation avait échoué, elle est retentée (sans doublon).
        if ($order->total === 0 && $order->status === Order::PENDING) {
            $this->confirm->handle($order->id);
        }

        return $order->refresh();
    }

    /**
     * @param  array<string, int>  $quantities
     * @param  array{name: string, email: string, phone?: ?string, marketing_consent?: bool}  $buyer
     */
    private function create(Event $event, array $quantities, array $buyer, string $locale, bool $hasPayment, ?string $idempotencyKey, ?string $referral): Order
    {
        return DB::transaction(function () use ($event, $quantities, $buyer, $locale, $hasPayment, $idempotencyKey, $referral) {
            [$ticketTypes, $remaining] = Inventory::lockRemaining(array_keys($quantities));

            // Après le verrou : un envoi identique simultané a attendu la fin du premier et le retrouve ici.
            $existing = $idempotencyKey !== null ? $this->existing($event, $idempotencyKey) : null;
            if ($existing !== null) {
                return $this->replay($existing, $quantities, $buyer);
            }

            if ($ticketTypes->where('event_id', $event->id)->count() !== count($quantities)) {
                throw ValidationException::withMessages(['items' => __('ticketing.unknown_ticket')]);
            }

            $errors = [];
            foreach ($quantities as $id => $quantity) {
                $ticketType = $ticketTypes[$id];
                $left = $remaining[$id];
                $error = match (true) {
                    ! $ticketType->isOnSale() => __('ticketing.not_on_sale', ['name' => $ticketType->name]),
                    $quantity > $ticketType->max_per_order => __('ticketing.max_per_order', ['name' => $ticketType->name, 'max' => $ticketType->max_per_order]),
                    $left === 0 => __('ticketing.sold_out', ['name' => $ticketType->name]),
                    $quantity > $left => __('ticketing.only_left', ['name' => $ticketType->name, 'left' => $left]),
                    default => null,
                };
                if ($error !== null) {
                    $errors["items.{$id}"] = $error;
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $total = collect($quantities)->sum(fn (int $quantity, string $id) => $quantity * $ticketTypes[$id]->price);
            if ($total > 0 && ! $hasPayment) {
                throw ValidationException::withMessages(['payment.method' => __('ticketing.choose_payment')]);
            }

            $order = new Order([
                // 10 caractères tirés au hasard (50 bits) : pas de doublon même après des millions de
                // commandes, et « kassEvent KE… » tient dans les 22 caractères du message pawaPay.
                'reference' => 'KE'.TicketCode::generate(10),
                'idempotency_key' => $idempotencyKey,
                'currency' => $event->currency,
                'total' => $total,
                'buyer_name' => $buyer['name'],
                'buyer_email' => $buyer['email'],
                'buyer_phone' => $buyer['phone'] ?? null,
                'marketing_consent' => $buyer['marketing_consent'] ?? false,
                'locale' => $locale,
                'referral' => $referral,
                'access_key' => Str::random(40),
                'expires_at' => now()->addMinutes(config('payments.hold_minutes')),
            ]);
            $order->event()->associate($event);
            $order->save();
            TenantKeys::remember($order->id, $order->organization_id);

            foreach ($ticketTypes->sortBy('position') as $id => $ticketType) {
                $order->items()->create([
                    'ticket_type_id' => $id,
                    'name' => $ticketType->name,
                    'quantity' => $quantities[$id],
                    'unit_price' => $ticketType->price,
                ]);
            }

            return $order;
        });
    }

    /**
     * Envoi répété : seulement pour la même commande (mêmes billets, même acheteur), et tant
     * qu'elle peut encore être payée ; sinon la page d'achat recommence avec une nouvelle clé.
     *
     * @param  array<string, int>  $quantities
     * @param  array{name: string, email: string, phone?: ?string, marketing_consent?: bool}  $buyer
     */
    private function replay(Order $order, array $quantities, array $buyer): Order
    {
        $ordered = $order->items()->pluck('quantity', 'ticket_type_id')->all();
        ksort($ordered);
        ksort($quantities);
        if ($ordered !== $quantities || $order->buyer_email !== $buyer['email']) {
            throw ValidationException::withMessages(['idempotency_key' => __('ticketing.order_changed')]);
        }
        if ($order->isExpired()) {
            throw ValidationException::withMessages(['items' => __('ticketing.order_expired')]);
        }

        return $order;
    }

    private function existing(Event $event, string $idempotencyKey): ?Order
    {
        return Order::where('event_id', $event->id)->where('idempotency_key', $idempotencyKey)->first();
    }
}
