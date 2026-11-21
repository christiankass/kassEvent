<?php

namespace App\Modules\Access\Http\Middleware;

use App\Modules\Access\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ventes, remboursements, versements, abonnement : les rôles qui touchent à l'argent
 * (propriétaire, administrateur, finance) doivent avoir activé la double authentification.
 * À placer après « tenant » (le rôle dépend de l'organisation courante).
 */
final class RequireTwoFactorForMoney
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->hasAnyRole(Roles::TWO_FACTOR_REQUIRED) && ! $user->hasTwoFactor()) {
            return response()->json([
                'message' => __('ticketing.two_factor_required'),
                'code' => 'two_factor_required',
            ], 403);
        }

        return $next($request);
    }
}
