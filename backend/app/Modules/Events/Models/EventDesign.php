<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\Color;
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
    public const FONTS = [
        'fraunces' => ['Fraunces:wght@700', "'Fraunces', Georgia, serif"],
        'playfair' => ['Playfair+Display:wght@700', "'Playfair Display', Georgia, serif"],
        'instrument' => ['Instrument+Sans:wght@700', "'Instrument Sans', Helvetica, Arial, sans-serif"],
        'script' => ['Great+Vibes', "'Great Vibes', 'Brush Script MT', cursive"],
    ];

    public const BANNERS = ['none', 'cover', 'custom'];

    /** @return array<string, string> réglages d'un événement pas encore personnalisé */
    public static function defaults(): array
    {
        return ['template' => DesignTemplates::DEFAULT, ...DesignTemplates::get(DesignTemplates::DEFAULT), 'banner' => 'none'];
    }

    /** Apparence par défaut, non enregistrée. */
    public static function defaultFor(Event $event): self
    {
        return (new self(['event_id' => $event->id, ...self::defaults()]))->setRelation('event', $event);
    }

    /**
     * Apparence à modifier : celle enregistrée, créée au premier changement. createOrFirst résiste
     * à deux créations simultanées (event_id unique). Événement d'une autre organisation : 404.
     */
    public static function editable(string $eventId): self
    {
        $event = Event::with('design')->findOrFail($eventId);
        $design = $event->design ?? self::createOrFirst(['event_id' => $event->id], self::defaults());
        $design->wasRecentlyCreated = false; // la réponse est une mise à jour (200), même au premier changement

        return $design->setRelation('event', $event);
    }

    /** Dossier des images du studio d'un événement (supprimé avec l'événement). */
    public static function studioDirectory(Event $event): string
    {
        return "designs/{$event->organization_id}/{$event->id}";
    }

    public function usesCanvas(): bool
    {
        return $this->mode === 'canvas' && $this->canvas && $this->canvas_image_path;
    }

    protected function casts(): array
    {
        return ['canvas' => 'array', 'poster_canvas' => 'array'];
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

    /** @return array{on_primary: string, on_accent: string, stub: string} couleurs de texte lisibles et fond de la zone du QR code */
    public function colors(): array
    {
        return [
            'on_primary' => Color::textOn($this->primary_color),
            'on_accent' => Color::textOn($this->accent_color),
            'stub' => Color::pale($this->accent_color),
        ];
    }

    public function fontStack(): string
    {
        return self::FONTS[$this->font][1];
    }

    public function fontUrl(): string
    {
        return 'https://fonts.googleapis.com/css2?family='.self::FONTS[$this->font][0].'&display=swap';
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

    public static function url(?string $path): ?string
    {
        return $path ? Event::mediaDisk()->url($path) : null;
    }

    /** Titre du billet : celui de l'organisateur, sinon « Billet d'entrée » ou « Vous êtes invité(e) ». */
    public function headlineText(): string
    {
        return $this->headline ?: __("designs.headline.{$this->kind}");
    }
}
