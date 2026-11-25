<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;
use Illuminate\Http\Client\PendingRequest;

/**
 * Messenger (API d'envoi de la page Facebook). Une page ne peut écrire qu'à une personne qui lui
 * a écrit dans les dernières 24 heures : l'envoi part juste après que l'acheteur a ouvert la
 * conversation par le lien m.me de sa commande (voir DeliveryService::linkMessenger), d'où le type
 * « RESPONSE ». Un message d'accueil, une image par billet, puis un bouton vers la commande.
 */
final class MessengerChannel implements DeliveryChannel
{
    public function __construct(private readonly string $pageToken) {}

    public function parts(Delivery $delivery, TicketMessages $messages): iterable
    {
        yield '_intro' => fn () => $this->post($delivery, ['text' => $messages->intro()]);

        foreach ($messages->tickets() as $ticket) {
            yield $ticket->code => fn () => $this->post(
                $delivery,
                ['attachment' => ['type' => 'image', 'payload' => ['is_reusable' => false]]],
                fn (PendingRequest $request) => $request->attach('filedata', $messages->image($ticket), "billet-{$ticket->code}.jpg", ['Content-Type' => 'image/jpeg']),
            );
        }

        yield '_link' => fn () => $this->post($delivery, ['attachment' => [
            'type' => 'template',
            'payload' => [
                'template_type' => 'button',
                'text' => $messages->text('keep'),
                'buttons' => [['type' => 'web_url', 'url' => $messages->url(), 'title' => $messages->text('view')]],
            ],
        ]]);
    }

    public function confirmsAtOnce(): bool
    {
        return false;
    }

    /** @param  callable(PendingRequest): PendingRequest|null  $withFile  pièce jointe (image du billet) */
    private function post(Delivery $delivery, array $message, ?callable $withFile = null): string
    {
        $request = MetaGraph::client($this->pageToken);
        $request = $withFile ? $withFile($request) : $request->asForm();

        return (string) MetaGraph::ok($request->post('me/messages', [
            'recipient' => json_encode(['id' => $delivery->recipient]),
            'messaging_type' => 'RESPONSE',
            'message' => json_encode($message),
        ]))->json('message_id');
    }
}
