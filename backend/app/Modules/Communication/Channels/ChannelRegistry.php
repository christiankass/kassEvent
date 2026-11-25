<?php

namespace App\Modules\Communication\Channels;

use RuntimeException;

/** Canal effectif de chaque messagerie, selon la configuration (config/messaging.php). */
final class ChannelRegistry
{
    public function for(string $channel): DeliveryChannel
    {
        return match (self::mode($channel)) {
            'simulated' => new SimulatedChannel,
            'live' => match ($channel) {
                'whatsapp' => new WhatsAppChannel(
                    (string) config('messaging.whatsapp.token') ?: throw new RuntimeException('WHATSAPP_TOKEN manquant.'),
                    (string) config('messaging.whatsapp.phone_number_id') ?: throw new RuntimeException('WHATSAPP_PHONE_NUMBER_ID manquant.'),
                ),
                'messenger' => new MessengerChannel((string) config('messaging.messenger.page_token') ?: throw new RuntimeException('MESSENGER_PAGE_TOKEN manquant.')),
            },
            default => throw new RuntimeException("Canal {$channel} désactivé."),
        };
    }

    /** « live », « simulated » (jamais en production : le canal est alors désactivé) ou « off ». */
    public static function mode(string $channel): string
    {
        $mode = config("messaging.{$channel}.mode");

        return match (true) {
            $mode === 'live' => 'live',
            $mode === 'simulated' && ! app()->isProduction() => 'simulated',
            default => 'off',
        };
    }

    public static function enabled(string $channel): bool
    {
        return self::mode($channel) !== 'off';
    }

    public static function simulationEnabled(): bool
    {
        return self::mode('whatsapp') === 'simulated' || self::mode('messenger') === 'simulated';
    }
}
