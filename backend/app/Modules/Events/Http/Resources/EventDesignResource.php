<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Apparence d'un billet, pour l'atelier et la page de commande. Les couleurs de texte lisibles
 * sont calculées par le site (lib/designs.ts), comme par l'e-mail (EventDesign::colors()).
 *
 * @mixin EventDesign
 */
final class EventDesignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // canvas : billet fait dans le studio (image par billet) ; classic : billet de l'atelier.
            'mode' => $this->usesCanvas() ? 'canvas' : 'classic',
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
            'banner_url' => EventDesign::url($this->bannerPath()),
            'custom_banner_url' => EventDesign::url($this->banner_path),
            'logo_url' => EventDesign::url($this->logo_path),
        ];
    }
}
