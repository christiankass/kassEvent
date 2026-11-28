<?php

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Support\ContactBook;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Désinscription par le lien personnel d'un contact (identifiant + clé secrète), sans compte :
 * contact inconnu ou clé fausse, 404. Ne montre que le nom de l'organisation.
 */
final class PublicUnsubscribeController
{
    public function show(Request $request, string $id): JsonResponse
    {
        return TenantKeys::within($id, fn () => $this->payload($this->find($id, $request->query('key')))) ?? abort(404);
    }

    public function store(Request $request, ContactBook $book, string $id): JsonResponse
    {
        $key = $request->validate(['key' => ['required', 'string', 'max:64']])['key'];

        return TenantKeys::within($id, function () use ($book, $id, $key) {
            $contact = $this->find($id, $key);
            $book->unsubscribe($contact);

            return $this->payload($contact);
        }) ?? abort(404);
    }

    private function find(string $id, mixed $key): Contact
    {
        $contact = Contact::with('organization')->find($id);
        abort_unless($contact?->hasUnsubscribeKey($key), 404);

        return $contact;
    }

    private function payload(Contact $contact): JsonResponse
    {
        return response()->json(['data' => [
            'organization' => $contact->organization->name,
            'unsubscribed' => $contact->unsubscribed_at !== null,
        ]]);
    }
}
