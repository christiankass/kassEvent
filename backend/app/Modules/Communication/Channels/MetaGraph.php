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

    /**
     * Jeton expiré ou révoqué, permission manquante : panne de notre configuration, pas refus du
     * destinataire. Signalée (journal d'erreurs) et retentée, jamais imputée à l'acheteur.
     */
    private const CONFIGURATION = [3, 10, 190, 200];

    public static function client(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('messaging.graph_url'))->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
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
        // Messenger : la conversation a plus de 24 heures, la personne doit rouvrir le lien.
        if ($code === 10 && (int) $response->json('error.error_subcode') === 2018278) {
            throw new DeliveryFailed('meta_10');
        }
        $exception = new RequestException($response);
        if (in_array($code, self::CONFIGURATION, true) || in_array($response->status(), [401, 403], true)) {
            report($exception);

            throw $exception;
        }
        if ($response->clientError() && $response->status() !== 429 && ! in_array($code, self::RATE_LIMITS, true)) {
            throw new DeliveryFailed('meta_'.($code ?: $response->status()));
        }

        throw $exception;
    }
}
