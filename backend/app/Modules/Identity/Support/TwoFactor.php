<?php

namespace App\Modules\Identity\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Codes à usage unique (TOTP, RFC 6238) lus dans une application d'authentification
 * (Google Authenticator, Microsoft Authenticator, Authy…), et codes de secours.
 */
final class TwoFactor
{
    private const ISSUER = 'kassEvent';

    private const RECOVERY_CODES = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $user->email, $secret);
    }

    /** QR code à scanner avec l'application, en SVG. */
    public function qrSvg(string $otpauthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd));

        return $writer->writeString($otpauthUrl);
    }

    /**
     * Code de l'application : une marge de 30 s avant et après, et chaque code ne sert qu'une fois
     * (un code intercepté ne peut pas être rejoué dans sa fenêtre de validité).
     */
    public function verify(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $secret = $user->two_factor_secret;

        if ($secret === null || ! preg_match('/^\d{6}$/', $code) || ! $this->google2fa->verifyKey($secret, $code, 1)) {
            return false;
        }

        return Cache::add("two-factor-used:{$user->getKey()}:{$code}", true, now()->addMinutes(3));
    }

    /** @return list<string> */
    public function newRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::upper(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODES),
        );
    }

    /**
     * Utilise un code de secours (il est retiré de la liste). La ligne est verrouillée :
     * deux connexions simultanées ne peuvent ni réutiliser un code ni en faire revenir un.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::upper(trim($code));

        return DB::transaction(function () use ($user, $code) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $codes = $locked->two_factor_recovery_codes ?? [];
            $index = array_search($code, $codes, true);

            if ($index === false) {
                return false;
            }

            unset($codes[$index]);
            $locked->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

            return true;
        });
    }
}
