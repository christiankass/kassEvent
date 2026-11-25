<?php

namespace App\Modules\Ticketing\Http\Controllers;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\DeliveryService;
use App\Modules\Events\Models\Event;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Actions\CreateOrder;
use App\Modules\Ticketing\Http\Requests\DeliverOrderRequest;
use App\Modules\Ticketing\Http\Requests\PayOrderRequest;
use App\Modules\Ticketing\Http\Requests\StoreOrderRequest;
use App\Modules\Ticketing\Http\Resources\OrderResource;
use App\Modules\Ticketing\Jobs\SendTickets;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\TicketImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Achat sans compte depuis la page publique. L'acheteur suit ensuite sa commande grâce à un lien
 * personnel (identifiant + clé secrète) : commande inconnue ou clé fausse, 404.
 */
final class PublicOrderController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly TenantContext $context,
        private readonly DeliveryService $deliveries,
    ) {}

    public function store(StoreOrderRequest $request, CreateOrder $createOrder, string $slug): JsonResponse
    {
        $event = Event::publiclyVisible()->where('slug', $slug)->firstOrFail();

        return $this->context->run($event->organization_id, function () use ($request, $createOrder, $event) {
            $payment = $request->payment();

            $order = $createOrder->handle($event, $request->quantities(), $request->buyer(), app()->getLocale(), $payment !== null, $request->idempotencyKey());

            // Billets aussi sur WhatsApp : envoyés dès leur émission (tout de suite pour une commande gratuite).
            $whatsapp = $request->whatsapp();
            if ($whatsapp !== null && $order->wasRecentlyCreated && ChannelRegistry::enabled(Delivery::WHATSAPP)) {
                $this->deliveries->whatsapp($order, $whatsapp);
            }

            // Envoi répété : la commande existe déjà ; on ne lance un paiement que si le premier envoi
            // n'a pas pu le faire (au plus un en attente par commande, garanti par la base).
            if ($order->total > 0 && ($order->wasRecentlyCreated || ($order->status === Order::PENDING && ! $order->latestPayment()->exists()))) {
                $this->payments->start($order, $payment);
            }

            return $this->resource($order)->additional(['access_key' => $order->access_key])->response()
                ->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
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

            // Deux essais envoyés ensemble : la base n'accepte qu'un paiement en attente par commande.
            if ($this->payments->start($order, $request->payment()) === null) {
                throw ValidationException::withMessages(['payment' => __('ticketing.payment_already_started')]);
            }

            return $this->resource($order->unsetRelation('latestPayment'));
        }) ?? abort(404);
    }

    /**
     * Billets d'une commande payée envoyés sur WhatsApp, Messenger (lien m.me à ouvrir, rendu dans
     * messenger_url) ou de nouveau par e-mail. Chaque envoi WhatsApp est payant : débit limité.
     */
    public function deliver(DeliverOrderRequest $request, string $id): JsonResponse
    {
        return TenantKeys::within($id, function () use ($request, $id) {
            $order = $this->find($id, $request->validated('key'));
            $channel = $request->validated('channel');
            if (! $order->isPaid()) {
                throw ValidationException::withMessages(['channel' => __('communication.not_paid')]);
            }
            if ($channel !== 'email' && $this->deliveries->inProgress($order, $channel)) {
                throw ValidationException::withMessages(['channel' => __('communication.in_progress')]);
            }

            $messengerUrl = null;
            match ($channel) {
                'email' => SendTickets::dispatch($order->organization_id, $order->id),
                Delivery::WHATSAPP => $this->deliveries->whatsapp($order, $request->whatsapp()),
                Delivery::MESSENGER => $messengerUrl = $this->deliveries->messengerLink($order),
            };

            return $this->resource($order->unsetRelation('deliveries'))->additional(['messenger_url' => $messengerUrl])->response();
        }) ?? abort(404);
    }

    /** Image d'un billet fait dans le studio (QR code et nom compris), pour la page de commande. */
    public function ticketImage(Request $request, string $id, string $code): Response
    {
        return TenantKeys::within($id, function () use ($request, $id, $code) {
            $order = $this->find($id, $request->query('key'));
            abort_unless($order->isPaid(), 404);
            $ticket = $order->tickets()->with('item')->where('code', str_replace('-', '', $code))->first();
            $image = $ticket ? TicketImage::forTicket($order->event()->with('design')->first()->ticketDesign(), $ticket) : null;
            abort_unless($image !== null, 404);

            return response($image, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300']);
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
        $order->loadMissing(['event.organization', 'items', 'latestPayment', 'deliveries']);

        // Billets et apparence choisie par l'organisateur : seulement une fois la commande payée.
        return new OrderResource($order->isPaid() ? $order->loadMissing(['tickets.item', 'event.design']) : $order);
    }
}
