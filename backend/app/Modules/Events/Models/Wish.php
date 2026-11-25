<?php

namespace App\Modules\Events\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un vœu laissé dans le livre d'un événement, sans compte. L'organisateur peut le masquer ou le supprimer. */
#[Fillable(['name', 'message'])]
class Wish extends Model
{
    use BelongsToOrganization, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = ['hidden' => false];

    protected function casts(): array
    {
        return ['hidden' => 'boolean'];
    }

    /** @return BelongsTo<WishBook, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(WishBook::class, 'wish_book_id');
    }
}
