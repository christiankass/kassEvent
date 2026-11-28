<?php

namespace App\Modules\Campaigns\Support;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Support\ContactBook;
use App\Modules\Campaigns\Jobs\SendCampaignMessages;
use App\Modules\Campaigns\Mail\CampaignMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignMessage;
use App\Modules\Communication\Channels\DeliveryFailed;
use App\Modules\Communication\Events\WhatsAppButtonPressed;
use App\Modules\Communication\Events\WhatsAppStatusReceived;
use App\Modules\Communication\Support\WhatsAppAnnouncer;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Départ et envoi d'une campagne (e-mail ou annonce WhatsApp). Au départ, la liste des destinataires est figée (un envoi par
 * contact joignable) ; les e-mails partent ensuite par lots, dans la file « notifications ».
 */
final class CampaignSender
{
    public const BATCH = 50;

    /** Préfixes des références WhatsApp : accusés (biz_opaque_callback_data) et bouton « Ne plus recevoir ». */
    private const CALLBACK = 'cmp:';

    private const STOP = 'stop:';

    public function __construct(private readonly ContactBook $contacts, private readonly WhatsAppAnnouncer $whatsapp) {}

    /**
     * Lance une campagne en brouillon ou programmée ; rend faux sans effet pour une campagne déjà partie.
     * Son événement n'est plus publié (programmée puis dépubliée) : elle revient en brouillon sans partir.
     */
    public function start(Campaign $campaign): bool
    {
        $started = DB::transaction(function () use ($campaign) {
            $campaign = Campaign::with('event')->lockForUpdate()->find($campaign->id);
            if ($campaign === null || ! $campaign->isEditable()) {
                return null;
            }
            if ($campaign->eventUnavailable()) {
                $campaign->backToDraft();

                return null;
            }

            $count = CampaignMessage::insertUsing(
                ['id', 'organization_id', 'campaign_id', 'contact_id', 'status'],
                $this->contacts->audience($campaign->channel, $campaign->audience)->toBase()
                    ->selectRaw("gen_random_uuid(), organization_id, ?::uuid, id, 'queued'", [$campaign->id]),
            );
            $campaign->forceFill([
                'status' => $count > 0 ? Campaign::SENDING : Campaign::SENT,
                'started_at' => now(),
                'sent_at' => $count > 0 ? null : now(),
            ])->save();

            return $campaign;
        });

        if ($started?->status === Campaign::SENDING) {
            $started->messages()->orderBy('id')->pluck('id')->chunk(self::BATCH)->each(
                fn ($ids) => SendCampaignMessages::dispatch($started->organization_id, $started->id, $ids->values()->all())->afterCommit(),
            );
        }

        return $started !== null;
    }

    /**
     * Envoie les messages encore en file parmi $ids. Un contact désinscrit (ou supprimé) entre-temps est
     * sauté ; un échec d'envoi est compté sans bloquer les autres. Chaque message est réservé avant
     * l'envoi : un lot relancé après un arrêt brutal ne renvoie jamais un e-mail peut-être déjà parti.
     *
     * @param  list<string>  $ids
     */
    public function deliver(Campaign $campaign, array $ids): void
    {
        $campaign->loadMissing(['organization', 'event']);
        // Réservés par un essai précédent interrompu en plein envoi : comptés en échec, jamais renvoyés.
        $this->abandon($campaign, $ids, [CampaignMessage::SENDING]);
        $messages = $campaign->messages()->with('contact')->whereIn('id', $ids)->where('status', CampaignMessage::QUEUED)->get();

        $whatsapp = $campaign->channel === Campaign::WHATSAPP;
        foreach ($messages as $message) {
            if (! $this->move($message, CampaignMessage::QUEUED, CampaignMessage::SENDING)) {
                continue; // pris par un autre lot
            }
            $contact = $message->contact;
            if ($contact === null || ($whatsapp ? $contact->phone : $contact->email) === null || ! $contact->isReachable()) {
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::SKIPPED);

                continue;
            }
            try {
                $whatsapp ? $this->announce($campaign, $contact, $message)
                    : Mail::to($contact->email, $contact->name)->locale($contact->locale)->send(new CampaignMail($campaign, $contact, $message));
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::SENT);
            } catch (DeliveryFailed) {
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::FAILED); // numéro sans WhatsApp…
            } catch (Throwable $e) {
                if ($whatsapp) {
                    // Panne ou limite de débit de Meta : le message attend le prochain essai du lot.
                    $this->move($message, CampaignMessage::SENDING, CampaignMessage::QUEUED);

                    throw $e;
                }
                report($e);
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::FAILED);
            }
        }

        $this->completeIfDone($campaign);
    }

    /** Accusé WhatsApp d'une annonce : « lu » vaut ouverture ; un refus après coup compte en échec. */
    public function onWhatsAppStatus(WhatsAppStatusReceived $event): void
    {
        $this->withMessage($event->callback, function (CampaignMessage $message) use ($event) {
            match ($event->status) {
                'read' => $this->opened($message),
                'failed' => $this->move($message, CampaignMessage::SENT, CampaignMessage::FAILED),
                default => null,
            };
        });
    }

    /** « Ne plus recevoir » touché sous une annonce : le contact est désinscrit de cette organisation. */
    public function onWhatsAppButton(WhatsAppButtonPressed $event): void
    {
        if (str_starts_with($event->payload, self::STOP)) {
            $this->withMessage(substr($event->payload, strlen(self::STOP)), function (CampaignMessage $message) {
                if ($message->contact !== null) {
                    $this->contacts->unsubscribe($message->contact);
                }
            });
        }
    }

    private function announce(Campaign $campaign, Contact $contact, CampaignMessage $message): void
    {
        $ref = $campaign->id.':'.$message->id;
        $this->whatsapp->send(
            $contact->phone,
            $contact->locale,
            ['name' => $contact->name, 'organization' => $campaign->organization->name, 'text' => str_replace(['{nom}', '{name}'], $contact->name, $campaign->body)],
            self::CALLBACK.$ref,
            // Bouton « Voir l'événement » : {API}/public/campaigns/{{1}} → suivi du clic, puis page de l'événement.
            $campaign->id.'/messages/'.$message->id.'/click',
            self::STOP.$ref,
        );
    }

    /**
     * Envoi désigné par « campagne:message » (accusé ou bouton WhatsApp, sans organisation) : ouvert
     * dans l'organisation de la campagne, ignoré s'il est inconnu.
     *
     * @param  callable(CampaignMessage): void  $callback
     */
    private function withMessage(string $ref, callable $callback): void
    {
        if (str_starts_with($ref, self::CALLBACK)) {
            $ref = substr($ref, strlen(self::CALLBACK));
        }
        [$campaignId, $messageId] = array_pad(explode(':', $ref, 2), 2, '');
        if (! Str::isUuid($campaignId) || ! Str::isUuid($messageId)) {
            return;
        }
        TenantKeys::within($campaignId, function () use ($campaignId, $messageId, $callback) {
            $message = CampaignMessage::with('contact')->where('campaign_id', $campaignId)->find($messageId);
            if ($message !== null) {
                $callback($message);
            }
        });
    }

    /**
     * Lot abandonné (essais épuisés) : ses messages encore en file ou réservés passent en échec, pour
     * que la campagne se termine quand même.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $statuses
     */
    public function abandon(Campaign $campaign, array $ids, array $statuses = [CampaignMessage::QUEUED, CampaignMessage::SENDING]): void
    {
        $campaign->messages()->whereIn('id', $ids)->whereIn('status', $statuses)->update(['status' => CampaignMessage::FAILED]);
        $this->completeIfDone($campaign);
    }

    /** Ouverture (image invisible) : notée une fois par destinataire. */
    public function opened(CampaignMessage $message): void
    {
        CampaignMessage::whereKey($message->id)->whereNull('opened_at')->update(['opened_at' => now()]);
    }

    /** Clic sur le bouton : noté une fois (et vaut ouverture, si l'image a été bloquée). */
    public function clicked(CampaignMessage $message): void
    {
        CampaignMessage::whereKey($message->id)->whereNull('clicked_at')
            ->update(['clicked_at' => now(), 'opened_at' => DB::raw('coalesce(opened_at, now())')]);
    }

    /**
     * Chiffres par campagne, comptés sur ses envois.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, array{recipients_count: int, sent_count: int, failed_count: int, opened_count: int, clicked_count: int}>
     */
    public function counts(array $campaignIds): array
    {
        return CampaignMessage::toBase()->whereIn('campaign_id', $campaignIds)->groupBy('campaign_id')
            ->selectRaw("campaign_id, count(*) as recipients_count, count(*) filter (where status = 'sent') as sent_count, count(*) filter (where status = 'failed') as failed_count, count(opened_at) as opened_count, count(clicked_at) as clicked_count")
            ->get()->mapWithKeys(fn ($row) => [$row->campaign_id => array_map('intval', array_diff_key((array) $row, ['campaign_id' => 1]))])
            ->all();
    }

    /** Passe un message de $from à $to (rien s'il n'est plus en $from) ; rend vrai s'il a changé. */
    private function move(CampaignMessage $message, string $from, string $to): bool
    {
        return CampaignMessage::whereKey($message->id)->where('status', $from)
            ->update(['status' => $to, 'sent_at' => $to === CampaignMessage::SENT ? now() : null]) === 1;
    }

    private function completeIfDone(Campaign $campaign): void
    {
        if (! $campaign->messages()->whereIn('status', [CampaignMessage::QUEUED, CampaignMessage::SENDING])->exists()) {
            Campaign::whereKey($campaign->id)->where('status', Campaign::SENDING)
                ->update(['status' => Campaign::SENT, 'sent_at' => now()]);
        }
    }
}
