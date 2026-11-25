<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Wish;
use App\Modules\Events\Models\WishBook;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Livre de vœux vu par l'organisation : réglages (ouvert, vœux affichés), lecture et modération. */
final class WishController
{
    public function index(string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $book = WishBook::where('event_id', $event->id)->first();
        $wishes = $book ? $book->wishes()->get() : collect();

        return response()->json([
            'book' => $book ? self::bookPayload($book) : null,
            'data' => $wishes->map(self::wishPayload(...))->values(),
            'summary' => ['total' => $wishes->count(), 'hidden' => $wishes->where('hidden', true)->count()],
        ]);
    }

    /** Ouvre le livre (créé au premier appel), le ferme, ou choisit d'afficher les vœux sur la page. */
    public function settings(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate(['open' => ['sometimes', 'boolean'], 'public' => ['sometimes', 'boolean']]);
        $event = Event::findOrFail($eventId);

        $book = WishBook::where('event_id', $event->id)->first();
        if ($book) {
            $book->forceFill($data)->save();
        } else {
            $book = DB::transaction(function () use ($event, $data) {
                $book = new WishBook;
                $book->event()->associate($event)->forceFill($data)->save();
                TenantKeys::remember($book->id, $book->organization_id);

                return $book;
            });
        }

        return response()->json(['book' => self::bookPayload($book)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $wish = Wish::whereHas('book.event')->findOrFail($id);
        $wish->forceFill(['hidden' => (bool) $data['hidden']])->save();

        return response()->json(['data' => self::wishPayload($wish)]);
    }

    public function destroy(string $id): Response
    {
        Wish::whereHas('book.event')->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    public static function bookPayload(WishBook $book): array
    {
        return ['id' => $book->id, 'open' => $book->open, 'public' => $book->public, 'url' => $book->publicUrl()];
    }

    /** @return array<string, mixed> */
    public static function wishPayload(Wish $wish): array
    {
        return [
            'id' => $wish->id,
            'name' => $wish->name,
            'message' => $wish->message,
            'hidden' => $wish->hidden,
            'created_at' => $wish->created_at->toIso8601String(),
        ];
    }
}
