<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\Color;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Apparence d'un billet, avec les couleurs de texte déjà calculées pour rester lisibles
 * (blanc ou encre selon la couleur choisie). Utilisée par l'atelier et par la page de commande.
 *
 * @mixin EventDesign
 */
final class EventDesignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'template' => $this->template,
            'kind' => $this->kind,
            'primary_color' => $this->primary_color,
            'accent_color' => $this->accent_color,
            'font' => $this->font,
            'headline' => $this->headline,
            'headline_text' => $this->headlineText(),
            'message' => $this->message,
            'footer' => $this->footer,
            'banner' => $this->banner,
            'banner_url' => $this->bannerUrl(),
            'custom_banner_url' => $this->banner_path ? Event::mediaDisk()->url($this->banner_path) : null,
            'logo_url' => $this->logoUrl(),
            'colors' => [
                'on_primary' => Color::textOn($this->primary_color),
                'on_accent' => Color::textOn($this->accent_color),
                'stub' => Color::pale($this->accent_color),
            ],
        ];
    }
}
