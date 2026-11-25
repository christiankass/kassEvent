<?php

namespace App\Modules\Payouts\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactor;
use App\Modules\Payments\Support\Phone;
use App\Modules\Payouts\Http\Requests\UpdatePayoutAccountRequest;
use App\Modules\Payouts\Http\Resources\PayoutAccountResource;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Notifications\PayoutAccountChanged;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Où l'organisation reçoit son argent (payouts.manage, code de double authentification redemandé).
 * Un changement ne reçoit des reversements qu'après un délai de sécurité, et tous les responsables
 * des versements sont prévenus par e-mail.
 */
final class PayoutAccountController
{
    public function update(UpdatePayoutAccountRequest $request, TwoFactor $twoFactor): JsonResponse
    {
        $user = $request->user();

        if (! $twoFactor->verify($user, (string) $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => __('identity.two_factor.invalid')]);
        }

        $save = fn () => DB::transaction(function () use ($request, $user) {
            $account = PayoutAccount::lockForUpdate()->first();
            $delay = $account === null ? 0 : (int) config('payouts.account_change_delay_hours');

            $account ??= new PayoutAccount;
            $account->fill([...$request->account(), 'updated_by' => $user->id, 'active_from' => now()->addHours($delay)]);
            $account->save();

            return $account;
        });
        try {
            $account = $save();
        } catch (UniqueConstraintViolationException) {
            $account = $save(); // premier compte enregistré au même moment par quelqu'un d'autre : on le remplace
        }

        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        $recipients = $organization->members()->get()->filter(fn (User $member) => $member->hasPermissionTo('payouts.manage'));
        Notification::send($recipients, new PayoutAccountChanged(
            $organization->name,
            $user->name,
            $account->operator,
            $account->operator === null ? $account->bank_name.' ••••'.$account->last4() : Phone::mask($account->phone),
            $account->active_from,
        ));

        // 200 même pour un premier compte : la ressource (unique) est remplacée, pas ajoutée.
        return PayoutAccountResource::make($account)->response()->setStatusCode(200);
    }
}
