<?php

namespace App\Modules\Identity\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    use NormalizesEmail;

    private const MAX_ATTEMPTS = 5;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Vérifie les identifiants, avec 5 essais par minute pour un même e-mail depuis une même adresse IP.
     * Le message d'erreur ne dit jamais si c'est l'e-mail ou le mot de passe qui est faux.
     * Ne connecte pas : avec la double authentification, la connexion attend le code.
     */
    public function validateCredentials(): User
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ])->status(429);
        }

        $guard = Auth::guard('web');

        if (! $guard->validate($this->only('email', 'password'))) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        /** @var User */
        return $guard->getLastAttempted();
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate((string) $this->string('email')).'|'.$this->ip();
    }
}
