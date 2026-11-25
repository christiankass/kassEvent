<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;

/**
 * Messenger (API d'envoi de la page Facebook). Une page ne peut écrire qu'à une personne qui lui
 * a écrit dans les dernières 24 heures : l'envoi part juste après que l'acheteur a ouvert la
 * conversation par le lien m.me de sa commande (voir DeliveryService::linkMessenger), d'où le type
 * « RESPONSE ». Un message d'accueil, une image par billet, puis un bouton vers la commande.
 */
final class MessengerChannel implements DeliveryChannel
{
    /** Messages encore permis pendant cet appel. */
    private int $left = 0;

    public function __construct(private readonly string $pageToken) {}

    public function send(Delivery $delivery, TicketMessages $messages, int $limit): bool
    {
        $this->left = $limit;
        $this->once($delivery, '_intro', fn () => $this->post($delivery, ['message' => json_encode(['text' => $messages->intro()])]));

        foreach ($messages->tickets() as $ticket) {
            $this->once($delivery, $ticket->code, fn () => MetaGraph::ok(MetaGraph::client($this->pageToken)
                ->attach('filedata', $messages->image($ticket), "billet-{$ticket->code}.jpg", ['Content-Type' => 'image/jpeg'])
                ->post('me/messages', [
                    'recipient' => json_encode(['id' => $delivery->recipient]),
                    'messaging_type' => 'RESPONSE',
                    'message' => json_encode(['attachment' => ['type' => 'image', 'payload' => ['is_reusable' => false]]]),
                ]))->json('message_id'));
        }

        $this->once($delivery, '_link', fn () => $this->post($delivery, ['message' => json_encode(['attachment' => [
            'type' => 'template',
            'payload' => [
                'template_type' => 'button',
                'text' => $messages->text('keep'),
                'buttons' => [['type' => 'web_url', 'url' => $messages->url(), 'title' => $messages->text('view')]],
            ],
        ]])]));

        return isset($delivery->messages['_link']);
    }

    /** @param  array<string, string>  $body */
    private function post(Delivery $delivery, array $body): string
    {
        return (string) MetaGraph::ok(MetaGraph::client($this->pageToken)->asForm()->post('me/messages', [
            'recipient' => json_encode(['id' => $delivery->recipient]),
            'messaging_type' => 'RESPONSE',
            ...$body,
        ]))->json('message_id');
    }

    /** N'envoie qu'une fois chaque message, même si l'envoi est retenté ; au plus $left par appel. */
    private function once(Delivery $delivery, string $key, callable $send): void
    {
        if (isset($delivery->messages[$key]) || $this->left === 0) {
            return;
        }
        $this->left--;
        $delivery->messages = [...($delivery->messages ?? []), $key => (string) $send()];
        $delivery->save();
    }
}
