<?php

namespace App\Modules\Contributions\Http\Controllers;

use App\Modules\Contributions\Http\Requests\PayContributionRequest;
use App\Modules\Contributions\Http\Requests\StoreContributionRequest;
use App\Modules\Contributions\Http\Resources\ContributionResource;
use App\Modules\Contributions\Models\Contribution;
use App\Modules\Events\Models\WishBook;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contribution sans compte depuis la page du livre d'or. Le contributeur suit ensuite son paiement
 * par un lien personnel (identifiant + clé secrète) : contribution inconnue ou clé fausse, 404.
 */
final class PublicContributionController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function store(StoreContributionRequest $request, string $id): JsonResponse
    {
        return TenantKeys::within($id, function () use ($request, $id) {
            $book = WishBook::whereHas('event')->findOrFail($id);
            // Envoi répété dont la réponse s'était perdue : on rend la contribution, même fermée depuis.
            $contribution = $this->existing($book, $request->idempotencyKey());
            if ($contribution === null && ! $book->contributions_open) {
                throw ValidationException::withMessages(['amount' => __('contributions.closed')]);
            }

            $contribution ??= $this->create($book, $request);
            // Envoi répété (double clic, réseau coupé) : on ne relance un paiement que si le premier n'est pas parti.
            if ($contribution->wasRecentlyCreated || ($book->contributions_open && ! $contribution->isPaid() && ! $contribution->latestPayment()->exists())) {
                $this->payments->start($contribution, $request->payment());
            }

            return $this->resource($contribution)->additional(['access_key' => $contribution->access_key])->response()
                ->setStatusCode($contribution->wasRecentlyCreated ? 201 : 200);
        }) ?? abort(404);
    }

    public function show(Request $request, string $id): ContributionResource
    {
        return TenantKeys::within($id, function () use ($request, $id) {
            $contribution = $this->find($id, $request->query('key'));
            if ($contribution->latestPayment?->isPending()) {
                $this->payments->refresh($contribution->latestPayment);
            }

            return $this->resource($contribution->refresh());
        }) ?? abort(404);
    }

    /** Nouvel essai ; jamais deux paiements en cours à la fois (comme pour une commande). */
    public function pay(PayContributionRequest $request, string $id): ContributionResource
    {
        return TenantKeys::within($id, function () use ($request, $id) {
            $contribution = $this->find($id, $request->validated('key'));
            $payment = $request->payment();
            $pending = $contribution->latestPayment?->isPending() ? $this->payments->refresh($contribution->latestPayment, force: true) : null;

            $error = match (true) {
                $contribution->refresh()->isPaid() => __('contributions.already_paid'),
                ! $contribution->book->contributions_open => __('contributions.closed'),
                (int) $request->validated('total') !== $contribution->amountToCharge($payment['method']) => __('contributions.total_changed'),
                $pending?->isPending() && ! $this->payments->cancel($pending) => __('ticketing.payment_in_progress'),
                default => null,
            };
            if ($error !== null) {
                throw ValidationException::withMessages(['payment' => $error]);
            }

            if ($this->payments->start($contribution, $payment) === null) {
                throw ValidationException::withMessages(['payment' => __('ticketing.payment_already_started')]);
            }

            return $this->resource($contribution->unsetRelation('latestPayment'));
        }) ?? abort(404);
    }

    /** Contribution déjà créée par le même envoi (même clé d'idempotence). */
    private function existing(WishBook $book, ?string $key): ?Contribution
    {
        return $key === null ? null : Contribution::where('wish_book_id', $book->id)->where('idempotency_key', $key)->first();
    }

    private function create(WishBook $book, StoreContributionRequest $request): Contribution
    {
        $key = $request->idempotencyKey();

        try {
            return DB::transaction(function () use ($book, $request, $key) {
                $contribution = new Contribution([
                    ...$request->contribution(),
                    'reference' => 'KC'.TicketCode::generate(10),
                    'access_key' => Str::random(40),
                    'idempotency_key' => $key,
                    'locale' => app()->getLocale(),
                ]);
                $contribution->book()->associate($book)->save();
                TenantKeys::remember($contribution->id, $contribution->organization_id);

                return $contribution;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Même envoi arrivé deux fois en même temps : l'autre demande l'a créée.
            return $this->existing($book, $key) ?? throw $e;
        }
    }

    private function find(string $id, mixed $key): Contribution
    {
        $contribution = Contribution::find($id);
        abort_unless($contribution?->hasAccessKey($key), 404);

        return $contribution;
    }

    private function resource(Contribution $contribution): ContributionResource
    {
        return new ContributionResource($contribution->loadMissing(['book.event.organization', 'latestPayment']));
    }
}
