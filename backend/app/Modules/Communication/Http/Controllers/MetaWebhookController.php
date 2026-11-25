<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Notifications de l'application Meta (WhatsApp et Messenger), à déclarer à l'adresse
 * {APP_URL}/api/v1/webhooks/meta. Chaque notification est signée avec le secret de l'application
 * (X-Hub-Signature-256) : sans signature valide, rien n'est lu.
 */
final class MetaWebhookController
{
    /** Vérification de l'adresse par Meta, au moment où on la déclare. */
    public function verify(Request $request): Response
    {
        $token = (string) config('messaging.verify_token');
        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe'
            && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): JsonResponse
    {
        abort_unless(self::validSignature($request->getContent(), (string) $request->header('X-Hub-Signature-256')), 400);

        foreach ((array) $request->input('entry', []) as $entry) {
            match ($request->input('object')) {
                'whatsapp_business_account' => $this->whatsapp((array) ($entry['changes'] ?? [])),
                'page' => $this->messenger((array) ($entry['messaging'] ?? [])),
                default => null,
            };
        }

        return response()->json(['received' => true]);
    }

    public static function validSignature(string $payload, string $header): bool
    {
        $secret = (string) config('messaging.app_secret');

        return $secret !== '' && str_starts_with($header, 'sha256=')
            && hash_equals(hash_hmac('sha256', $payload, $secret), substr($header, 7));
    }

    /** Accusés des messages envoyés : notre identifiant d'envoi revient dans biz_opaque_callback_data. */
    private function whatsapp(array $changes): void
    {
        foreach ($changes as $change) {
            foreach ((array) data_get($change, 'value.statuses', []) as $status) {
                $deliveryId = data_get($status, 'biz_opaque_callback_data');
                $messageId = data_get($status, 'id');
                $state = data_get($status, 'status');
                if (is_string($deliveryId) && is_string($messageId) && in_array($state, [Delivery::SENT, Delivery::DELIVERED, Delivery::READ, Delivery::FAILED], true)) {
                    $code = data_get($status, 'errors.0.code');
                    DeliveryService::recordStatus($deliveryId, $messageId, $state, is_scalar($code) ? 'meta_'.$code : null);
                }
            }
        }
    }

    /**
     * Personne arrivée par le lien m.me d'une commande : « Démarrer » d'une première conversation
     * (postback.referral), conversation existante (referral) ou module « Envoyer dans Messenger » (optin).
     */
    private function messenger(array $events): void
    {
        foreach ($events as $event) {
            $psid = data_get($event, 'sender.id');
            $ref = data_get($event, 'referral.ref') ?? data_get($event, 'postback.referral.ref') ?? data_get($event, 'optin.ref');
            if (is_string($psid) && is_string($ref)) {
                DeliveryService::linkMessenger($ref, $psid);
            }
        }
    }
}
