<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\DesignTemplates;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apparence des billets (ou invitations) d'un événement : modèle, couleurs, police, textes, logo
 * et bannière. Le QR code, lui, reste toujours noir sur blanc pour rester lisible au scan.
 */
#[Fillable(['event_id', 'template', 'kind', 'primary_color', 'accent_color', 'font', 'headline', 'message', 'footer', 'banner'])]
class EventDesign extends Model
{
    use BelongsToOrganization, HasUuids;

    public const TICKET = 'ticket';

    public const INVITATION = 'invitation';

    public const KINDS = [self::TICKET, self::INVITATION];

    /** Polices proposées : famille Google Fonts et polices de repli présentes partout (e-mails). */
    private const FONT_FAMILIES = [
        'fraunces' => ['Fraunces:wght@700', "'Fraunces', Georgia, serif"],
        'playfair' => ['Playfair+Display:wght@700', "'Playfair Display', Georgia, serif"],
        'instrument' => ['Instrument+Sans:wght@700', "'Instrument Sans', Helvetica, Arial, sans-serif"],
        'script' => ['Great+Vibes', "'Great Vibes', 'Brush Script MT', cursive"],
    ];

    public const FONTS = ['fraunces', 'playfair', 'instrument', 'script'];

    public const BANNERS = ['none', 'cover', 'custom'];

    /** Apparence par défaut d'un événement qui n'a pas encore été personnalisé (non enregistrée). */
    public static function defaultFor(Event $event): self
    {
        $design = new self(['template' => DesignTemplates::DEFAULT, ...DesignTemplates::get(DesignTemplates::DEFAULT), 'banner' => 'none']);
        $design->event_id = $event->id;

        return $design->setRelation('event', $event);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isInvitation(): bool
    {
        return $this->kind === self::INVITATION;
    }

    public function fontStack(): string
    {
        return self::FONT_FAMILIES[$this->font][1];
    }

    public function fontUrl(): string
    {
        return 'https://fonts.googleapis.com/css2?family='.self::FONT_FAMILIES[$this->font][0].'&display=swap';
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Event::mediaDisk()->url($this->logo_path) : null;
    }

    /** Chemin de l'image de bannière effectivement affichée (affiche de l'événement ou image dédiée). */
    public function bannerPath(): ?string
    {
        return match ($this->banner) {
            'cover' => $this->event->cover_path,
            'custom' => $this->banner_path,
            default => null,
        };
    }

    public function bannerUrl(): ?string
    {
        $path = $this->bannerPath();

        return $path ? Event::mediaDisk()->url($path) : null;
    }

    /** Titre du billet : celui de l'organisateur, sinon « Billet d'entrée » ou « Vous êtes invité(e) ». */
    public function headlineText(): string
    {
        return $this->headline ?: __("designs.headline.{$this->kind}");
    }
}
