<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inscription et connexion ouvrent une session par cookie : seules les requêtes du site
 * (domaine déclaré à Sanctum, voir FRONTEND_URL) en ont une. Les autres sont refusées d'emblée.
 */
final class RequireSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasSession(), 400, __('identity.session_required'));

        return $next($request);
    }
}
