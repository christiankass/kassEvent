<?php

namespace App\Modules\Ticketing\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\Inventory;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Réserve les places d'une commande. À appeler dans le contexte de l'organisation de l'événement.
 * Les types de billets sont verrouillés pendant le calcul : deux acheteurs simultanés ne peuvent
 * pas prendre ensemble plus de places qu'il n'en reste.
 */
final class CreateOrder
{
    public function __construct(private readonly ConfirmOrder $confirm) {}

    /**
     * @param  array<string, int>  $quantities  identifiant du type de billet => quantité (> 0)
     * @param  array{name: string, email: string}  $buyer
     * @param  bool  $hasPayment  un moyen de paiement est choisi (obligatoire si la commande n'est pas gratuite)
     */
    public function handle(Event $event, array $quantities, array $buyer, string $locale, bool $hasPayment): Order
    {
        if ($event->starts_at->isPast()) {
            throw ValidationException::withMessages(['items' => __('ticketing.event_started')]);
        }

        $order = DB::transaction(function () use ($event, $quantities, $buyer, $locale, $hasPayment) {
            [$ticketTypes, $remaining] = Inventory::lockRemaining(array_keys($quantities));

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
                'reference' => 'KE'.TicketCode::generate(6),
                'currency' => $event->currency,
                'total' => $total,
                'buyer_name' => $buyer['name'],
                'buyer_email' => $buyer['email'],
                'locale' => $locale,
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

        // Billets gratuits sur inscription : rien à payer, les billets partent tout de suite.
        if ($order->total === 0) {
            $this->confirm->handle($order->id);
        }

        return $order->refresh();
    }
}
