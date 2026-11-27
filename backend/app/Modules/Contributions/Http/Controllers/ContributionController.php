<?php

namespace App\Modules\Contributions\Http\Controllers;

use App\Modules\Contributions\Models\Contribution;
use App\Modules\Events\Http\Controllers\WishController;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\WishBook;
use App\Modules\Events\Support\PlainText;
use App\Modules\Payouts\Support\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Contributions d'un livre d'or vues par l'organisation (rôles argent, double authentification) :
 * ouvrir ou fermer les contributions, nommer le bénéficiaire, lire qui a donné combien.
 */
final class ContributionController
{
    public function index(string $eventId): JsonResponse
    {
        $book = WishBook::where('event_id', Event::findOrFail($eventId)->id)->first();
        $paid = $book ? Contribution::where('wish_book_id', $book->id)->where('status', Contribution::PAID)->orderByDesc('paid_at')->get() : collect();

        return response()->json([
            'data' => $paid->map(fn (Contribution $contribution) => [
                'id' => $contribution->id,
                'reference' => $contribution->reference,
                'contributor_name' => $contribution->contributor_name,
                'message' => $contribution->message,
                'amount' => $contribution->amount,
                'currency' => $contribution->currency,
                'paid_at' => $contribution->paid_at->toIso8601String(),
            ])->values(),
            'totals' => $paid->groupBy('currency')->map(fn ($group, $currency) => [
                'currency' => $currency, 'amount' => $group->sum('amount'), 'count' => $group->count(),
            ])->values(),
            'has_payout_account' => PayoutService::hasAccount(),
        ]);
    }

    /** Ouvre ou ferme les contributions ; il faut d'abord dire où recevoir l'argent (page Versements). */
    public function settings(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'open' => ['sometimes', 'boolean'],
            'beneficiary' => ['sometimes', 'nullable', 'string', 'max:80', PlainText::noLinks('events.no_links')],
        ]);
        $book = WishBook::where('event_id', Event::findOrFail($eventId)->id)->firstOrFail();

        if (($data['open'] ?? false) && ! PayoutService::hasAccount()) {
            throw ValidationException::withMessages(['open' => __('contributions.no_payout_account')]);
        }
        if (array_key_exists('open', $data)) {
            $book->contributions_open = (bool) $data['open'];
        }
        if (array_key_exists('beneficiary', $data)) {
            $beneficiary = PlainText::clean((string) $data['beneficiary'], singleLine: true);
            $book->beneficiary = $beneficiary !== '' ? $beneficiary : null;
        }
        $book->save();

        return response()->json(['book' => WishController::bookPayload($book)]);
    }
}
