<?php

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Channels\DeliveryFailed;
use App\Modules\Communication\Channels\MetaGraph;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Annonce WhatsApp d'une campagne (contrat publié du module Communication). Une annonce part
 * forcément d'un modèle « Marketing » approuvé par Meta (kassevent_annonce) : nom du contact,
 * organisation et texte de l'organisateur dans le corps, bouton « Voir l'événement » (suivi des
 * clics) et bouton « Ne plus recevoir » (désinscription en un geste).
 *
 * Mode simulé (sans compte Meta, jamais en production) : rien ne part, le texte va au journal ;
 * un numéro qui finit par 0000 simule « numéro sans WhatsApp ».
 */
final class WhatsAppAnnouncer
{
    /** Longueur maximale du texte de l'organisateur (le corps d'un modèle est limité à 1024 caractères). */
    public const MAX_TEXT = 900;

    public static function available(): bool
    {
        return ChannelRegistry::enabled('whatsapp');
    }

    public static function simulated(): bool
    {
        return ChannelRegistry::mode('whatsapp') === 'simulated';
    }

    /**
     * Envoie l'annonce ; rend l'identifiant WhatsApp du message. Refus définitif (numéro sans WhatsApp,
     * modèle refusé) : DeliveryFailed ; panne ou limite de débit : autre exception, à retenter.
     *
     * @param  array{name: string, organization: string, text: string}  $content
     * @param  string  $callback  revient tel quel dans les accusés (remis, lu)
     * @param  string  $buttonSuffix  partie variable de l'adresse du bouton « Voir l'événement »
     * @param  string  $stopPayload  revient quand la personne touche « Ne plus recevoir »
     */
    public function send(string $phone, string $locale, array $content, string $callback, string $buttonSuffix, string $stopPayload): string
    {
        $body = array_map(fn (string $text) => ['type' => 'text', 'text' => MetaGraph::param($text, self::MAX_TEXT)], [$content['name'], $content['organization'], $content['text']]);

        if (self::simulated()) {
            if (str_ends_with($phone, (string) config('messaging.simulated_failure_suffix'))) {
                throw new DeliveryFailed('not_on_whatsapp');
            }
            Log::info('[whatsapp simulé] annonce', ['to' => $phone, 'text' => $content['text'], 'callback' => $callback]);

            return 'sim-'.Str::uuid();
        }
        if (! self::available()) {
            throw new DeliveryFailed('channel_disabled');
        }

        return (string) MetaGraph::ok(MetaGraph::client((string) config('messaging.whatsapp.token'))
            ->post(config('messaging.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'biz_opaque_callback_data' => $callback,
                'template' => [
                    'name' => config('messaging.whatsapp.templates.announcement'),
                    'language' => ['code' => $locale],
                    'components' => [
                        ['type' => 'body', 'parameters' => $body],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonSuffix]]],
                        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => $stopPayload]]],
                    ],
                ],
            ]))->json('messages.0.id');
    }

    /** Meta refuse les retours à la ligne, tabulations et plus de 4 espaces de suite dans une variable. */
}
