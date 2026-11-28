<?php

namespace App\Modules\Campaigns\Support;

use App\Modules\Audience\Support\ContactBook;
use App\Modules\Campaigns\Jobs\SendCampaignMessages;
use App\Modules\Campaigns\Mail\CampaignMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Départ et envoi d'une campagne e-mail. Au départ, la liste des destinataires est figée (un envoi par
 * contact joignable) ; les e-mails partent ensuite par lots, dans la file « notifications ».
 */
final class CampaignSender
{
    public const BATCH = 50;

    public function __construct(private readonly ContactBook $contacts) {}

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
                $this->contacts->emailAudience($campaign->audience)->toBase()
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

        foreach ($messages as $message) {
            if (! $this->move($message, CampaignMessage::QUEUED, CampaignMessage::SENDING)) {
                continue; // pris par un autre lot
            }
            $contact = $message->contact;
            if ($contact === null || $contact->email === null || ! $contact->isReachable()) {
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::SKIPPED);

                continue;
            }
            try {
                Mail::to($contact->email, $contact->name)->locale($contact->locale)->send(new CampaignMail($campaign, $contact, $message));
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::SENT);
            } catch (Throwable $e) {
                report($e);
                $this->move($message, CampaignMessage::SENDING, CampaignMessage::FAILED);
            }
        }

        $this->completeIfDone($campaign);
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
