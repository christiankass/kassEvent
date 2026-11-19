<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Support\TwoFactorChallenge;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Connexion de l'application web par cookie de session (Sanctum SPA) : aucun jeton n'est
 * exposé au JavaScript du navigateur. Le site appelle d'abord GET /sanctum/csrf-cookie.
 */
final class AuthController
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->safe()->only(['name', 'email', 'password']),
            'locale' => $request->validated('locale', app()->getLocale()),
        ]);

        event(new Registered($user));
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * Identifiants corrects : connexion immédiate, ou, si la double authentification est activée,
     * étape du code (POST /auth/two-factor-challenge) avant toute session connectée.
     */
    public function login(LoginRequest $request, TwoFactorChallenge $challenge): JsonResponse
    {
        $user = $request->validateCredentials();

        if ($user->hasTwoFactor()) {
            $challenge->start($request, $user, $request->boolean('remember'));

            return response()->json(['two_factor' => true]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return UserResource::make($user)->response();
    }

    public function logout(Request $request): Response
    {
        // Client à jeton (futures applications) : on révoque ce jeton.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
