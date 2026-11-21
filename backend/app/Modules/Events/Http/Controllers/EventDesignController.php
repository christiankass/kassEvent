<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Requests\EventDesignRequest;
use App\Modules\Events\Http\Resources\EventDesignResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\DesignImage;
use App\Modules\Events\Support\DesignTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Atelier de personnalisation des billets et invitations d'un événement
 * (lecture : events.view ; modification : events.manage). L'aperçu de l'e-mail est dans Ticketing.
 */
final class EventDesignController
{
    /** Formats acceptés (jamais de SVG, qui peut contenir du script) et taille maximale après réduction. */
    private const IMAGES = [
        'logo' => [['required', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64'], 480, 480],
        'banner' => [['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=150'], 1200, 600],
    ];

    /** Avec les modèles de départ et les titres par défaut, utiles à l'aperçu en direct. */
    public function show(string $id): JsonResponse
    {
        return EventDesignResource::make($this->design($id))->additional(['meta' => [
            'templates' => DesignTemplates::all(),
            'headlines' => array_combine(EventDesign::KINDS, array_map(fn ($kind) => __("designs.headline.{$kind}"), EventDesign::KINDS)),
        ]])->response();
    }

    public function update(EventDesignRequest $request, string $id): EventDesignResource
    {
        $design = EventDesign::editable($id);
        $design->fill($request->toAttributes());
        $design->mode = 'classic'; // enregistrer l'atelier repasse au billet simple

        if ($design->banner !== 'none' && ! $design->bannerPath()) {
            throw ValidationException::withMessages(['banner' => __("designs.banner_needs_{$design->banner}")]);
        }
        $design->save();

        return EventDesignResource::make($design);
    }

    /** Envoie le logo ou la bannière ; l'ancienne image est supprimée. */
    public function upload(Request $request, string $id, string $image): EventDesignResource
    {
        [$rules, $maxWidth, $maxHeight] = self::IMAGES[$image];
        $request->validate(['image' => $rules]);
        $design = EventDesign::editable($id);
        $column = "{$image}_path";
        $previous = $design->{$column};

        $path = DesignImage::store($request->file('image'), "designs/{$design->event->organization_id}", $maxWidth, $maxHeight);
        $design->{$column} = $path;
        if ($image === 'banner') {
            $design->banner = 'custom';
        }
        try {
            $design->save();
        } catch (Throwable $e) {
            Event::mediaDisk()->delete($path); // pas de fichier orphelin si l'enregistrement échoue

            throw $e;
        }

        if ($previous) {
            Event::mediaDisk()->delete($previous);
        }

        return EventDesignResource::make($design);
    }

    public function removeImage(string $id, string $image): EventDesignResource
    {
        $design = $this->design($id);
        $column = "{$image}_path";

        if ($design->{$column}) {
            Event::mediaDisk()->delete($design->{$column});
            $design->{$column} = null;
            if ($image === 'banner' && $design->banner === 'custom') {
                $design->banner = 'none';
            }
            $design->save();
        }

        return EventDesignResource::make($design);
    }

    /** Apparence enregistrée, sinon celle par défaut (pas encore enregistrée). Événement d'une autre organisation : 404. */
    private function design(string $id): EventDesign
    {
        return Event::with('design')->findOrFail($id)->ticketDesign();
    }
}
