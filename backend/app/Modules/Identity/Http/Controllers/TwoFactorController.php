<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Support\TwoFactor;
use App\Modules\Identity\Support\TwoFactorChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Double authentification du compte : activer (secret + QR code), confirmer par un premier code
 * (codes de secours remis une seule fois), régénérer les codes, désactiver. Chaque changement
 * redemande le mot de passe.
 */
final class TwoFactorController
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function enable(Request $request): JsonResponse
    {
        self::confirmPassword($request);
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['password' => __('identity.two_factor.already_enabled')]);
        }

        $secret = $this->twoFactor->newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();
        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => $this->twoFactor->qrSvg($url),
        ]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if ($user->hasTwoFactor() || $user->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => __('identity.two_factor.not_started')]);
        }
        if (! $this->twoFactor->verify($user, (string) $request->string('code'))) {
            throw ValidationException::withMessages(['code' => __('identity.two_factor.invalid')]);
        }

        $codes = $this->twoFactor->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        self::confirmPassword($request);
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 404);

        $codes = $this->twoFactor->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function disable(Request $request): Response
    {
        self::confirmPassword($request);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->noContent();
    }

    private static function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']]);
    }

    /** Étape du code à la connexion. */
    public function challenge(Request $request, TwoFactorChallenge $challenge): UserResource
    {
        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:20'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ]);

        return UserResource::make($challenge->complete($request, $data['code'] ?? null, $data['recovery_code'] ?? null));
    }
}
