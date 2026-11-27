<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Contributions\Support\ContributionOptions;
use App\Modules\Events\Http\Resources\EventDesignResource;
use App\Modules\Events\Models\Wish;
use App\Modules\Events\Models\WishBook;
use App\Modules\Events\Support\PlainText;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Page publique du livre de vœux, ouverte par son lien (identifiant aléatoire), sans compte.
 * Livre inconnu ou événement supprimé : 404. Contre le spam : limite de débit (routes), champ piège,
 * liens refusés, doublons ignorés ; l'organisateur masque ou supprime ensuite ce qu'il veut.
 */
final class PublicWishController
{
    public function show(string $id): JsonResponse
    {
        return TenantKeys::within($id, fn () => $this->payload($this->find($id))) ?? abort(404);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $noLinks = PlainText::noLinks();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60', $noLinks],
            'message' => ['required', 'string', 'min:2', 'max:1000', $noLinks],
            // Champ piège, caché aux personnes : seul un robot le remplit.
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        return TenantKeys::within($id, function () use ($id, $data) {
            $book = $this->find($id);
            if (! $book->open) {
                throw ValidationException::withMessages(['message' => __('events.wishes_closed')]);
            }

            $name = PlainText::clean($data['name'], singleLine: true);
            $message = PlainText::clean($data['message']);
            // Texte fait seulement d'espaces ou de caractères invisibles.
            foreach (['name' => $name, 'message' => $message] as $field => $value) {
                if (mb_strlen($value) < 2) {
                    throw ValidationException::withMessages([$field => __('validation.required', ['attribute' => $field])]);
                }
            }
            // Robot (champ piège rempli) ou même vœu envoyé deux fois : réponse normale, rien d'enregistré.
            $duplicate = $book->wishes()->where('name', $name)->where('message', $message)->exists();
            if (filled($data['website'] ?? null) || $duplicate) {
                return response()->json(['data' => ['saved' => true]], 201);
            }

            $wish = new Wish(['name' => $name, 'message' => $message]);
            $wish->book()->associate($book)->save();

            return response()->json(['data' => ['saved' => true]], 201);
        }) ?? abort(404);
    }

    private function find(string $id): WishBook
    {
        return WishBook::whereHas('event')->findOrFail($id);
    }

    private function payload(WishBook $book): JsonResponse
    {
        $book->loadMissing(['event.organization', 'event.design']);
        $event = $book->event;
        $visible = $book->wishes()->where('hidden', false);

        return response()->json(['data' => [
            'event' => [
                'title' => $event->title,
                'description' => $event->description,
                'starts_at_utc' => $event->starts_at->toIso8601String(),
                'timezone' => $event->timezone,
                'cover_url' => $event->coverUrl(),
                'organization' => ['name' => $event->organization->name],
            ],
            'design' => EventDesignResource::make($event->ticketDesign()),
            'open' => $book->open,
            'public' => $book->public,
            'tone' => $book->tone,
            'contributions' => $book->contributions_open ? ContributionOptions::for($event->organization->currency, $book->beneficiary) : null,
            'count' => (clone $visible)->count(),
            'wishes' => $book->public
                ? (clone $visible)->limit(WishBook::PUBLIC_LIMIT)->get()
                    ->map(fn (Wish $wish) => ['name' => $wish->name, 'message' => $wish->message, 'created_at' => $wish->created_at->toIso8601String()])
                : [],
        ]]);
    }
}
