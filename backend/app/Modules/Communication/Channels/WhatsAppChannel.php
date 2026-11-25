<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;

/**
 * WhatsApp Cloud API. Un billet = un message à partir d'un modèle approuvé par Meta (obligatoire
 * pour écrire à quelqu'un qui n'a pas écrit dans les dernières 24 heures) : l'image du billet en
 * en-tête, le nom, le billet, l'événement, la date et le code dans le texte, et un bouton vers la
 * page de la commande. L'identifiant de l'envoi revient dans les accusés (biz_opaque_callback_data).
 */
final class WhatsAppChannel implements DeliveryChannel
{
    public function __construct(private readonly string $token, private readonly string $phoneNumberId) {}

    public function send(Delivery $delivery, TicketMessages $messages): void
    {
        $order = $messages->order;
        $template = config('messaging.whatsapp.templates.'.($messages->isInvitation() ? 'invitation' : 'ticket'));
        // Partie variable du bouton « Voir ma commande » : https://app.kassevent.com/commande/{{1}}
        $suffix = $order->id.'?cle='.urlencode($order->access_key);

        foreach ($messages->tickets() as $ticket) {
            if (isset($delivery->messages[$ticket->code])) {
                continue;
            }
            $mediaId = MetaGraph::ok(MetaGraph::client($this->token)
                ->attach('file', $messages->image($ticket), "billet-{$ticket->code}.jpg", ['Content-Type' => 'image/jpeg'])
                ->post("{$this->phoneNumberId}/media", ['messaging_product' => 'whatsapp', 'type' => 'image/jpeg']))->json('id');

            $messageId = MetaGraph::ok(MetaGraph::client($this->token)->post("{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $delivery->recipient,
                'type' => 'template',
                'biz_opaque_callback_data' => $delivery->id,
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $order->locale],
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => $mediaId]]]],
                        ['type' => 'body', 'parameters' => array_map(fn (string $text) => ['type' => 'text', 'text' => self::param($text)], [
                            $ticket->holder_name, $ticket->item->name, $order->event->title, $messages->when, $messages->code($ticket),
                        ])],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
                    ],
                ],
            ]))->json('messages.0.id');

            $delivery->messages = [...($delivery->messages ?? []), $ticket->code => (string) $messageId];
            $delivery->save();
        }
    }

    /** Meta refuse les retours à la ligne, tabulations et plus de 4 espaces de suite dans une variable. */
    private static function param(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }
}
