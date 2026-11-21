<?php

namespace App\Modules\Ticketing\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Actions\CreateOrder;
use App\Modules\Ticketing\Http\Requests\PayOrderRequest;
use App\Modules\Ticketing\Http\Requests\StoreOrderRequest;
use App\Modules\Ticketing\Http\Resources\OrderResource;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Achat sans compte depuis la page publique. L'acheteur suit ensuite sa commande grâce à un lien
 * personnel (identifiant + clé secrète) : commande inconnue ou clé fausse, 404.
 */
final class PublicOrderController
{
    public function __construct(private readonly PaymentService $payments, private readonly TenantContext $context) {}

    public function store(StoreOrderRequest $request, CreateOrder $createOrder, string $slug): JsonResponse
    {
        $event = Event::publiclyVisible()->where('slug', $slug)->firstOrFail();

        return $this->context->run($event->organization_id, function () use ($request, $createOrder, $event) {
            $payment = $request->payment();

            $order = $createOrder->handle($event, $request->quantities(), $request->buyer(), app()->getLocale(), $payment !== null);

            if ($order->total > 0) {
                $this->payments->start($order, $payment);
            }

            return $this->resource($order)->additional(['access_key' => $order->access_key])->response()->setStatusCode(201);
        });
    }

    public function show(Request $request, string $id): OrderResource
    {
        return TenantKeys::within($id, function () use ($request, $id) {
            $order = $this->find($id, $request->query('key'));

            if ($order->latestPayment?->isPending() && ! $this->payments->refresh($order->latestPayment)->isPending()) {
                $order->refresh(); // paiement terminé : statut de la commande changé
            }

            return $this->resource($order);
        }) ?? abort(404);
    }

    /**
     * Nouvel essai. Jamais deux paiements en cours à la fois : une page de carte quittée est d'abord
     * annulée chez Stripe ; un mobile money en attente doit recevoir sa réponse de l'opérateur.
     */
    public function pay(PayOrderRequest $request, string $id): OrderResource
    {
        return TenantKeys::within($id, function () use ($request, $id) {
            $order = $this->find($id, $request->validated('key'));
            $pending = $order->latestPayment?->isPending() ? $this->payments->refresh($order->latestPayment, force: true) : null;

            $error = match (true) {
                $order->refresh()->status !== Order::PENDING => __('ticketing.order_paid'),
                $order->isExpired() => __('ticketing.order_expired'),
                $order->total === 0 => __('ticketing.free_order'),
                $pending?->isPending() && ! $this->payments->cancel($pending) => __('ticketing.payment_in_progress'),
                default => null,
            };
            if ($error !== null) {
                throw ValidationException::withMessages(['payment' => $error]);
            }

            $this->payments->start($order, $request->payment());

            return $this->resource($order->unsetRelation('latestPayment'));
        }) ?? abort(404);
    }

    private function find(string $id, mixed $key): Order
    {
        $order = Order::find($id);
        abort_unless($order?->hasAccessKey($key), 404);

        return $order;
    }

    private function resource(Order $order): OrderResource
    {
        $order->loadMissing(['event.organization', 'event.design', 'items', 'latestPayment']);

        return new OrderResource($order->isPaid() ? $order->loadMissing('tickets.item') : $order);
    }
}
