<?php

namespace App\Modules\Identity\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Étape du code à la connexion : après le mot de passe, la session garde l'utilisateur
 * en attente (5 minutes, 5 essais) ; il n'est connecté qu'avec un bon code.
 */
final class TwoFactorChallenge
{
    private const SESSION_KEY = 'two_factor.login';

    private const MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function start(Request $request, User $user, bool $remember): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'id' => $user->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);
    }

    /** Vérifie le code (ou un code de secours) et connecte l'utilisateur. */
    public function complete(Request $request, ?string $code, ?string $recoveryCode): User
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        $user = is_array($pending) && $pending['expires_at'] >= now()->getTimestamp()
            ? User::find($pending['id'])
            : null;

        if (! $user?->hasTwoFactor()) {
            $request->session()->forget(self::SESSION_KEY);
            throw ValidationException::withMessages(['code' => __('identity.two_factor.expired')]);
        }

        $key = 'two-factor-challenge:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $request->session()->forget(self::SESSION_KEY);
            throw ValidationException::withMessages(['code' => __('identity.two_factor.too_many')])->status(429);
        }

        // Compté avant la vérification : des essais lancés en parallèle ne passent pas entre les mailles.
        RateLimiter::hit($key, 15 * 60);

        $valid = $recoveryCode !== null
            ? $this->twoFactor->useRecoveryCode($user, $recoveryCode)
            : $code !== null && $this->twoFactor->verify($user, $code);

        if (! $valid) {
            throw ValidationException::withMessages([
                $recoveryCode !== null ? 'recovery_code' : 'code' => __('identity.two_factor.invalid'),
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('web')->login($user, (bool) $pending['remember']);
        $request->session()->regenerate();

        return $user;
    }
}
