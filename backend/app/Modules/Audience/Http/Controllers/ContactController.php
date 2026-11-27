<?php

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Carnet de contacts de l'organisation courante (orders.view : on y voit acheteurs et invités).
 * Contact d'une autre organisation : 404.
 */
final class ContactController
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'in:all,reachable,unsubscribed,no_consent'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $contacts = Contact::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                // « 0812… » saisi à la locale : les numéros sont enregistrés en international (243812…).
                $digits = ltrim((string) preg_replace('/\D/', '', $search), '0');
                $query->where(fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$like])
                    ->orWhere('email', 'like', $like)
                    ->when(strlen($digits) >= 3, fn (Builder $query) => $query->orWhere('phone', 'like', '%'.$digits.'%')));
            })
            ->when($filters['filter'] ?? 'all', fn (Builder $query, string $filter) => match ($filter) {
                'reachable' => $query->reachable(),
                'unsubscribed' => $query->whereNotNull('unsubscribed_at'),
                'no_consent' => $query->where('marketing_consent', false),
                default => $query,
            })
            ->orderByDesc('last_activity_at')->orderBy('id')
            ->paginate(self::PER_PAGE);

        $summary = Contact::toBase()->selectRaw(
            'count(*) as total, count(*) filter (where marketing_consent and unsubscribed_at is null) as reachable, count(*) filter (where unsubscribed_at is not null) as unsubscribed, count(email) as with_email, count(phone) as with_phone'
        )->first();

        return response()->json([
            'data' => $contacts->getCollection()->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'reachable' => $contact->isReachable(),
                'consented_at' => $contact->consented_at?->toIso8601String(),
                'consent_source' => $contact->consent_source,
                'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
                'orders_count' => $contact->orders_count,
                'invitations_count' => $contact->invitations_count,
                'last_activity_at' => $contact->last_activity_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage(), 'total' => $contacts->total()],
            'summary' => array_map('intval', (array) $summary),
        ]);
    }

    /** Suppression à la demande de la personne (ses données personnelles). Ses commandes restent. */
    public function destroy(string $id): Response
    {
        Contact::findOrFail($id)->delete();

        return response()->noContent();
    }
}
