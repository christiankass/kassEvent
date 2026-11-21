<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\CanvasDocument;
use App\Modules\Events\Support\DesignImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Studio : éditeur libre, en glisser-déposer, du billet (ou de l'invitation) et de l'affiche d'un
 * événement (lecture : events.view ; modification : events.manage). Le navigateur envoie le document
 * (calques) et son image fixe ; le QR code et le nom de chaque invité sont ajoutés à l'envoi des billets.
 */
final class StudioController
{
    private const IMAGE_TYPES = ['required', 'image', 'mimes:jpeg,png,webp'];

    public function show(string $id): JsonResponse
    {
        $design = $this->design($id);

        return response()->json([
            'data' => [
                'mode' => $design->modeName(),
                'kind' => $design->kind,
                'ticket' => $design->canvas,
                'poster' => $design->poster_canvas,
            ],
            'meta' => ['formats' => CanvasDocument::FORMATS, 'fields' => CanvasDocument::FIELDS],
        ]);
    }

    /** Photo ou logo ajouté dans l'éditeur, réduit à 2000 pixels au plus. */
    public function storeAsset(Request $request, string $id): JsonResponse
    {
        $request->validate(['image' => [...self::IMAGE_TYPES, 'max:10240']]);
        $event = Event::findOrFail($id);
        $path = DesignImage::store($request->file('image'), EventDesign::studioDirectory($event), 2000, 2000, 88);

        return response()->json(['data' => ['name' => basename($path)]], 201);
    }

    /**
     * Image de l'événement, lue par l'éditeur à travers l'API (ainsi le navigateur peut l'exporter
     * sans dépendre des en-têtes du stockage). « cover » : l'affiche actuelle.
     */
    public function asset(string $id, string $name): StreamedResponse
    {
        $event = Event::findOrFail($id);
        $path = $name === 'cover' ? $event->cover_path : EventDesign::studioDirectory($event).'/'.$name;
        $disk = Event::mediaDisk();
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Enregistre le billet du studio et l'utilise désormais pour les billets envoyés. */
    public function saveTicket(Request $request, string $id): JsonResponse
    {
        [$width, $height] = CanvasDocument::FORMATS[CanvasDocument::TICKET];
        $request->validate([
            'canvas' => ['required', 'string', 'max:500000'],
            'image' => [...self::IMAGE_TYPES, 'max:10240', "dimensions:width={$width},height={$height}"],
        ]);
        $design = EventDesign::editable($id);
        $canvas = $this->document($request, $design->event, CanvasDocument::TICKET);

        $previous = $design->canvas_image_path;
        $path = DesignImage::store($request->file('image'), EventDesign::studioDirectory($design->event), $width, $height, 90);
        $design->forceFill(['canvas' => $canvas, 'canvas_image_path' => $path, 'mode' => 'canvas']);
        $this->saveOrDiscard($design, $path);
        if ($previous) {
            Event::mediaDisk()->delete($previous);
        }

        return $this->show($id);
    }

    /** Enregistre l'affiche du studio, qui devient l'affiche de l'événement (page de vente, partages). */
    public function savePoster(Request $request, string $id): JsonResponse
    {
        [$width, $height] = CanvasDocument::FORMATS[CanvasDocument::POSTER];
        $request->validate([
            'canvas' => ['required', 'string', 'max:500000'],
            'image' => [...self::IMAGE_TYPES, 'max:10240', "dimensions:width={$width},height={$height}"],
        ]);
        $design = EventDesign::editable($id);
        $event = $design->event;
        $canvas = $this->document($request, $event, CanvasDocument::POSTER);

        $previous = $event->cover_path;
        $path = DesignImage::store($request->file('image'), "events/{$event->organization_id}", $width, $height, 90);
        $design->poster_canvas = $canvas;
        $this->saveOrDiscard($design, $path);
        $event->forceFill(['cover_path' => $path])->save();
        if ($previous) {
            Event::mediaDisk()->delete($previous);
        }

        return $this->show($id);
    }

    /** Revient au billet simple de l'atelier ; le design du studio est gardé pour plus tard. */
    public function useClassic(string $id): JsonResponse
    {
        $design = $this->design($id);
        if ($design->exists && $design->mode !== 'classic') {
            $design->forceFill(['mode' => 'classic'])->save();
        }

        return $this->show($id);
    }

    /** @return array<string, mixed> */
    private function document(Request $request, Event $event, string $usage): array
    {
        // Une seule lecture du dossier, au lieu d'une requête au stockage par image du document.
        $names = array_flip(array_map('basename', Event::mediaDisk()->files(EventDesign::studioDirectory($event))));

        return CanvasDocument::validate(json_decode($request->input('canvas'), true), $usage, fn (string $name) => isset($names[$name]));
    }

    private function saveOrDiscard(EventDesign $design, string $path): void
    {
        try {
            $design->save();
        } catch (Throwable $e) {
            Event::mediaDisk()->delete($path); // pas de fichier orphelin si l'enregistrement échoue

            throw $e;
        }
    }

    private function design(string $id): EventDesign
    {
        return Event::with('design')->findOrFail($id)->ticketDesign();
    }
}
