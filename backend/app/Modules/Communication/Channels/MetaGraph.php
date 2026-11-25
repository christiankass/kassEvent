<?php

namespace App\Modules\Communication\Channels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Appels à l'API Graph de Meta, partagés par WhatsApp et Messenger. */
final class MetaGraph
{
    /** Codes d'erreur de Meta qui signalent une limite de débit : à réessayer plus tard. */
    private const RATE_LIMITS = [4, 17, 32, 613, 80007, 130429, 131048, 131056];

    public static function client(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('messaging.graph_url'))->withToken($token)->acceptJson()->timeout(20)->connectTimeout(5);
    }

    /**
     * Rend la réponse réussie. Refus définitif (erreur 4xx hors limite de débit) : DeliveryFailed
     * avec le code de Meta ; panne ou limite de débit : exception, l'envoi sera retenté.
     */
    public static function ok(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }
        $code = (int) $response->json('error.code');
        if ($response->clientError() && $response->status() !== 429 && ! in_array($code, self::RATE_LIMITS, true)) {
            throw new DeliveryFailed('meta_'.($code ?: $response->status()));
        }

        throw new RequestException($response);
    }
}
