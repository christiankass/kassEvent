<?php

namespace App\Modules\Contributions\Models;

use App\Modules\Contributions\Support\ContributionFees;
use App\Modules\Events\Models\WishBook;
use App\Modules\Payments\Contracts\Payable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Contribution d'un proche sur la page du livre d'or (soutien, cadeau), sans compte. amount est ce
 * que reçoit le bénéficiaire ; les frais du prestataire s'y ajoutent au paiement, selon le moyen.
 * Le contributeur la suit grâce à un lien personnel (identifiant + clé secrète).
 */
class Contribution extends Model implements Payable
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    protected $guarded = ['id', 'organization_id', 'status', 'paid_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected $hidden = ['access_key'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'access_key' => 'encrypted', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<WishBook, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(WishBook::class, 'wish_book_id');
    }

    /** @return HasOne<Payment, $this> */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->orderByDesc('id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function hasAccessKey(mixed $key): bool
    {
        return is_string($key) && hash_equals($this->access_key, $key);
    }

    public function fee(string $method): int
    {
        return ContributionFees::for($this->amount, $this->currency, $method);
    }

    public function paymentOwner(): array
    {
        return ['contribution_id' => $this->id];
    }

    public function amountToCharge(string $method): int
    {
        return $this->amount + $this->fee($method);
    }

    public function paymentCurrency(): string
    {
        return $this->currency;
    }

    public function paymentReference(): string
    {
        return $this->reference;
    }

    public function paymentLines(string $method): array
    {
        $title = $this->book->event->title;
        $lines = [['name' => __('contributions.line', ['title' => $title], $this->locale), 'quantity' => 1, 'unit_amount' => $this->amount]];
        $fee = $this->fee($method);

        return $fee > 0 ? [...$lines, ['name' => __('contributions.fee_line', [], $this->locale), 'quantity' => 1, 'unit_amount' => $fee]] : $lines;
    }

    public function payerEmail(): ?string
    {
        return null;
    }

    public function paymentLocale(): string
    {
        return $this->locale;
    }

    /** Page de la contribution sur le site (lien personnel du contributeur). */
    public function publicUrl(): string
    {
        return config('app.frontend_url').'/contribution/'.$this->id.'?cle='.urlencode($this->access_key);
    }
}
