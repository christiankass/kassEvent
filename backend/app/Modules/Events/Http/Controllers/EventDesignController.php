<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Requests\EventDesignRequest;
use App\Modules\Events\Http\Resources\EventDesignResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\DesignTemplates;
use App\Modules\Ticketing\Mail\TicketsMail;
use App\Modules\Ticketing\Support\QrCode;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Atelier de personnalisation des billets et invitations d'un événement
 * (lecture : events.view ; modification : events.manage).
 */
final class EventDesignController
{
    /** Taille et formats des images : jamais de SVG (il peut contenir du script). */
    private const IMAGES = [
        'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64'],
        'banner' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=150'],
    ];

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->design($id));
    }

    public function update(EventDesignRequest $request, string $id): JsonResponse
    {
        $design = $this->storedDesign($id);
        $design->fill($request->toAttributes());

        $error = match ($design->banner) {
            'cover' => $design->event->cover_path ? null : __('designs.banner_needs_cover'),
            'custom' => $design->banner_path ? null : __('designs.banner_needs_image'),
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['banner' => $error]);
        }

        $design->save();

        return $this->respond($design);
    }

    /** Envoie le logo ou la bannière ; l'ancienne image est supprimée. */
    public function upload(Request $request, string $id, string $image): JsonResponse
    {
        $request->validate(['image' => self::IMAGES[$image]]);
        $design = $this->storedDesign($id);
        $disk = Event::mediaDisk();
        $column = "{$image}_path";
        $previous = $design->{$column};

        $path = $disk->putFile("designs/{$design->event->organization_id}", $request->file('image'));
        $design->{$column} = $path;
        if ($image === 'banner') {
            $design->banner = 'custom';
        }
        try {
            $design->save();
        } catch (Throwable $e) {
            $disk->delete($path); // pas de fichier orphelin si l'enregistrement échoue

            throw $e;
        }

        if ($previous) {
            $disk->delete($previous);
        }

        return $this->respond($design);
    }

    public function removeImage(string $id, string $image): JsonResponse
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

        return $this->respond($design);
    }

    /** L'e-mail des billets tel qu'il partira, avec un billet d'exemple (le site l'affiche dans un cadre isolé, sans script). */
    public function preview(string $id): JsonResponse
    {
        $mail = new TicketsMail(TicketsMail::sample($this->design($id)->event, auth()->user()->name));

        return response()->json(['data' => ['subject' => $mail->envelope()->subject, 'html' => $mail->render()]]);
    }

    /** Envoie l'e-mail d'exemple à l'adresse de l'utilisateur connecté, et à elle seule. */
    public function test(string $id): JsonResponse
    {
        $user = auth()->user();
        $event = $this->design($id)->event;

        Mail::to($user->email, $user->name)->send(new TicketsMail(TicketsMail::sample($event, $user->name)));

        return response()->json(['message' => __('designs.test_sent', ['email' => $user->email])]);
    }

    /** Apparence enregistrée, sinon celle par défaut (pas encore enregistrée). Événement d'une autre organisation : 404. */
    private function design(string $id): EventDesign
    {
        return Event::with('design')->findOrFail($id)->ticketDesign();
    }

    /**
     * Apparence enregistrée, créée avec les réglages par défaut si besoin. createOrFirst résiste
     * à deux premières modifications simultanées (event_id unique).
     */
    private function storedDesign(string $id): EventDesign
    {
        $event = Event::findOrFail($id);
        $defaults = EventDesign::defaultFor($event)->only(['template', 'kind', 'primary_color', 'accent_color', 'font', 'banner']);

        return EventDesign::createOrFirst(['event_id' => $event->id], $defaults)->setRelation('event', $event);
    }

    private function respond(EventDesign $design): JsonResponse
    {
        return EventDesignResource::make($design)->additional(['meta' => [
            'templates' => DesignTemplates::all(),
            // QR code de l'exemple, pour l'aperçu en direct (il ne correspond à aucun billet).
            'sample_qr' => 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg(TicketsMail::SAMPLE_CODE)),
            'sample_code' => TicketCode::format(TicketsMail::SAMPLE_CODE),
        ]])->response()->setStatusCode(200);
    }
}
