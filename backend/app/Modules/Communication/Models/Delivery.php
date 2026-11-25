<?php

namespace App\Modules\Communication\Models;

use App\Modules\Payments\Support\Phone;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envoi des billets d'une commande sur un canal (WhatsApp ou Messenger). Son identifiant revient
 * dans les notifications de Meta (biz_opaque_callback_data, lien m.me) : il est inscrit dans tenant_keys.
 */
class Delivery extends Model
{
    use BelongsToOrganization, HasUuids;

    public const WHATSAPP = 'whatsapp';

    public const MESSENGER = 'messenger';

    public const CHANNELS = [self::WHATSAPP, self::MESSENGER];

    /** Commande pas encore payée : part dès l'émission des billets. */
    public const SCHEDULED = 'scheduled';

    /** Messenger : la personne doit d'abord ouvrir la conversation avec la page (lien m.me). */
    public const AWAITING_CONTACT = 'awaiting_contact';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    /**
     * Ordre des accusés de Meta : un accusé en retard ne fait jamais reculer le statut. Pendant
     * l'envoi (queued), les accusés des premiers billets sont ignorés : l'envoi doit rester « en
     * cours » pour qu'un nouvel essai envoie les billets restants.
     */
    private const PROGRESS = [self::SENT => 1, self::DELIVERED => 2, self::READ => 3];

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['recipient', 'link_secret'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'messages' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Accusé de Meta (sent, delivered, read) : seulement s'il fait avancer l'envoi. */
    public function advancesTo(string $status): bool
    {
        return isset(self::PROGRESS[$this->status], self::PROGRESS[$status])
            && self::PROGRESS[$status] > self::PROGRESS[$this->status];
    }

    /** Destinataire affiché à l'acheteur : numéro masqué ; rien pour Messenger (identifiant interne à Meta). */
    public function maskedRecipient(): ?string
    {
        if ($this->channel !== self::WHATSAPP || $this->recipient === null) {
            return null;
        }

        return str_starts_with($this->recipient, '243')
            ? Phone::mask($this->recipient)
            : '+'.substr($this->recipient, 0, 4).' ••• •• '.substr($this->recipient, -2);
    }
}
