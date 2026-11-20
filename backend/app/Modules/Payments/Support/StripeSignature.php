<?php

namespace App\Modules\Payments\Support;

/** Vérifie l'en-tête Stripe-Signature (« t=…,v1=… ») : HMAC-SHA256 de « t.corps », 5 minutes de tolérance. */
final class StripeSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function valid(string $payload, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = ctype_digit($value) ? (int) $value : null;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** En-tête valide pour $payload (tests). */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}
