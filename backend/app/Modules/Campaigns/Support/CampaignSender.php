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

    /** Lance une campagne en brouillon ou programmée (sans effet sur une campagne déjà partie). */
    public function start(Campaign $campaign): void
    {
        $started = DB::transaction(function () use ($campaign) {
            $campaign = Campaign::lockForUpdate()->find($campaign->id);
            if ($campaign === null || ! $campaign->isEditable()) {
                return null;
            }

            CampaignMessage::insertUsing(
                ['id', 'organization_id', 'campaign_id', 'contact_id', 'status'],
                $this->contacts->emailAudience($campaign->audience)->toBase()
                    ->selectRaw("gen_random_uuid(), organization_id, ?::uuid, id, 'queued'", [$campaign->id]),
            );
            $count = $campaign->messages()->count();
            $campaign->forceFill([
                'status' => $count > 0 ? Campaign::SENDING : Campaign::SENT,
                'recipients_count' => $count,
                'started_at' => now(),
                'sent_at' => $count > 0 ? null : now(),
            ])->save();

            return $campaign;
        });

        if ($started?->status === Campaign::SENDING) {
            $started->messages()->orderBy('id')->pluck('id')->chunk(self::BATCH)->each(
                fn ($ids) => SendCampaignMessages::dispatch($started->organization_id, $started->id, $ids->values()->all()),
            );
        }
    }

    /**
     * Envoie les messages encore en file parmi $ids. Un contact désinscrit (ou supprimé) entre-temps est
     * sauté ; un échec d'envoi est compté sans bloquer les autres.
     *
     * @param  list<string>  $ids
     */
    public function deliver(Campaign $campaign, array $ids): void
    {
        $campaign->loadMissing(['organization', 'event']);
        $messages = $campaign->messages()->with('contact')->whereIn('id', $ids)->where('status', CampaignMessage::QUEUED)->get();

        foreach ($messages as $message) {
            $contact = $message->contact;
            if ($contact === null || $contact->email === null || ! $contact->isReachable()) {
                $this->finish($campaign, $message, CampaignMessage::SKIPPED);

                continue;
            }
            try {
                Mail::to($contact->email, $contact->name)->locale($contact->locale)->send(new CampaignMail($campaign, $contact, $message));
                $this->finish($campaign, $message, CampaignMessage::SENT);
            } catch (Throwable $e) {
                report($e);
                $this->finish($campaign, $message, CampaignMessage::FAILED);
            }
        }

        $this->completeIfDone($campaign);
    }

    /** Ouverture (image invisible) : comptée une fois par destinataire. */
    public function opened(CampaignMessage $message): void
    {
        if (CampaignMessage::whereKey($message->id)->whereNull('opened_at')->update(['opened_at' => now()]) === 1) {
            Campaign::whereKey($message->campaign_id)->increment('opened_count');
        }
    }

    /** Clic sur le bouton : compté une fois par destinataire (et vaut ouverture, si l'image a été bloquée). */
    public function clicked(CampaignMessage $message): void
    {
        $this->opened($message);
        if (CampaignMessage::whereKey($message->id)->whereNull('clicked_at')->update(['clicked_at' => now()]) === 1) {
            Campaign::whereKey($message->campaign_id)->increment('clicked_count');
        }
    }

    private function finish(Campaign $campaign, CampaignMessage $message, string $status): void
    {
        $done = CampaignMessage::whereKey($message->id)->where('status', CampaignMessage::QUEUED)
            ->update(['status' => $status, 'sent_at' => $status === CampaignMessage::SENT ? now() : null]);
        $counter = [CampaignMessage::SENT => 'sent_count', CampaignMessage::FAILED => 'failed_count'][$status] ?? null;
        if ($done === 1 && $counter !== null) {
            Campaign::whereKey($campaign->id)->increment($counter);
        }
    }

    private function completeIfDone(Campaign $campaign): void
    {
        if (! $campaign->messages()->where('status', CampaignMessage::QUEUED)->exists()) {
            Campaign::whereKey($campaign->id)->where('status', Campaign::SENDING)
                ->update(['status' => Campaign::SENT, 'sent_at' => now()]);
        }
    }
}
