<?php

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Audience\Support\ContactBook;
use App\Modules\Campaigns\Mail\CampaignMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Support\CampaignSender;
use App\Modules\Communication\Support\WhatsAppAnnouncer;
use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Campagnes e-mail de l'organisation courante (campaigns.manage). Campagne d'une autre organisation : 404.
 * Une campagne partie ne se modifie plus et ne se supprime plus (ses chiffres restent).
 */
final class CampaignController
{
    private const EDITABLE = [Campaign::DRAFT, Campaign::SCHEDULED];

    public function __construct(private readonly ContactBook $contacts, private readonly CampaignSender $sender) {}

    public function index(): JsonResponse
    {
        $campaigns = Campaign::with('event:id,title')->latest()->limit(100)->get();
        $counts = $this->sender->counts($campaigns->modelKeys());

        return response()->json([
            'data' => $campaigns->map(fn (Campaign $campaign) => $this->summary($campaign, $counts[$campaign->id] ?? [])),
            'audiences' => $this->contacts->audienceCounts(),
            'channels' => [
                // Sans service d'envoi configuré, les e-mails restent dans le journal du serveur.
                'email' => ['available' => true, 'demo' => in_array(config('mail.default'), ['log', 'array'], true)],
                // Sans compte WhatsApp Business, les annonces sont simulées (jamais en production).
                'whatsapp' => ['available' => WhatsAppAnnouncer::available(), 'demo' => WhatsAppAnnouncer::simulated()],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = new Campaign($this->validated($request));
        $campaign->created_by = $request->user()->id;
        $campaign->save();
        // Liens de suivi des e-mails : ils arrivent sans organisation.
        TenantKeys::remember($campaign->id, $campaign->organization_id);

        return response()->json(['data' => $this->detail($campaign)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail(Campaign::findOrFail($id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request);
        $campaign = $this->locked($id, self::EDITABLE, 'campaigns.locked', fn (Campaign $campaign) => $campaign->fill($data)->save());

        return response()->json(['data' => $this->detail($campaign)]);
    }

    public function destroy(string $id): Response
    {
        $this->locked($id, self::EDITABLE, 'campaigns.locked', fn (Campaign $campaign) => $campaign->delete());

        return response()->noContent();
    }

    /** Envoi tout de suite, ou programmé (scheduled_at). */
    public function send(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(90)->toDateTimeString()],
        ]);
        if ($campaign->eventUnavailable()) {
            throw ValidationException::withMessages(['event_id' => __('campaigns.event_unpublished')]);
        }
        if ($this->contacts->audience($campaign->channel, $campaign->audience)->doesntExist()) {
            throw ValidationException::withMessages(['audience' => __('campaigns.no_recipients')]);
        }

        if (isset($data['scheduled_at'])) {
            $this->locked($id, self::EDITABLE, 'campaigns.locked', fn (Campaign $locked) => $locked->forceFill(['status' => Campaign::SCHEDULED, 'scheduled_at' => $data['scheduled_at']])->save());
        } elseif (! $this->sender->start($campaign)) {
            throw ValidationException::withMessages(['status' => __('campaigns.locked')]);
        }

        return response()->json(['data' => $this->detail($campaign->refresh())]);
    }

    /** Programmée : revient en brouillon. */
    public function cancel(string $id): JsonResponse
    {
        $campaign = $this->locked($id, [Campaign::SCHEDULED], 'campaigns.not_scheduled', fn (Campaign $campaign) => $campaign->backToDraft());

        return response()->json(['data' => $this->detail($campaign)]);
    }

    /** Essai à l'adresse de la personne connectée, pour voir l'e-mail dans sa propre messagerie. */
    public function test(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::with(['organization', 'event'])->findOrFail($id);
        if ($campaign->channel !== Campaign::EMAIL) {
            throw ValidationException::withMessages(['channel' => __('campaigns.test_email_only')]);
        }
        $email = $request->user()->email;
        if (! $email) {
            throw ValidationException::withMessages(['email' => __('campaigns.no_email')]);
        }
        Mail::to($email, $request->user()->name)->send(new CampaignMail($campaign, test: true, sampleName: $request->user()->name));

        return response()->json(['data' => ['email' => $email]]);
    }

    /** Aperçu de l'e-mail tel qu'il partira (sans suivi ni désinscription réelle). */
    public function preview(string $id): JsonResponse
    {
        $campaign = Campaign::with(['organization', 'event'])->findOrFail($id);

        return response()->json(['data' => ['html' => (new CampaignMail($campaign))->render()]]);
    }

    /**
     * Change une campagne sous verrou, si elle est dans l'un des $statuses (sinon 422 $error) : le
     * lanceur de campagnes programmées ne peut pas la faire partir entre la vérification et l'écriture.
     *
     * @param  list<string>  $statuses
     * @param  callable(Campaign): mixed  $change
     */
    private function locked(string $id, array $statuses, string $error, callable $change): Campaign
    {
        return DB::transaction(function () use ($id, $statuses, $error, $change) {
            $campaign = Campaign::lockForUpdate()->findOrFail($id);
            if (! in_array($campaign->status, $statuses, true)) {
                throw ValidationException::withMessages(['status' => __($error)]);
            }
            $change($campaign);

            return $campaign;
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $whatsapp = $request->input('channel') === Campaign::WHATSAPP;
        $data = $request->validate([
            'channel' => ['sometimes', Rule::in(Campaign::CHANNELS)],
            'subject' => ['required', 'string', 'max:150'],
            // WhatsApp : texte d'une variable de modèle (une ligne, longueur limitée par Meta).
            'body' => ['required', 'string', 'max:'.($whatsapp ? WhatsAppAnnouncer::MAX_TEXT : 5000)],
            // WhatsApp : l'annonce porte toujours le bouton « Voir l'événement ».
            'event_id' => [$whatsapp ? 'required' : 'nullable', 'uuid'],
            'button_label' => ['nullable', 'string', 'max:40'],
            'audience' => ['required', Rule::in(Campaign::AUDIENCES)],
        ]);
        if ($whatsapp && ! WhatsAppAnnouncer::available()) {
            throw ValidationException::withMessages(['channel' => __('campaigns.whatsapp_unavailable')]);
        }
        // Événement de l'organisation seulement (le scope ne voit pas ceux des autres).
        if (($data['event_id'] ?? null) !== null && ! Event::whereKey($data['event_id'])->exists()) {
            throw ValidationException::withMessages(['event_id' => __('validation.exists', ['attribute' => 'event'])]);
        }

        return [
            ...$data,
            'subject' => trim($data['subject']),
            // Le bouton d'une annonce WhatsApp est écrit dans le modèle approuvé : pas de texte libre.
            'button_label' => $whatsapp ? null : (trim((string) ($data['button_label'] ?? '')) ?: null),
        ];
    }

    /** @return array<string, mixed> */
    /** @param  array<string, int>  $counts  chiffres de CampaignSender::counts() */
    private function summary(Campaign $campaign, array $counts): array
    {
        return [
            'id' => $campaign->id,
            'subject' => $campaign->subject,
            'channel' => $campaign->channel,
            'status' => $campaign->status,
            'audience' => $campaign->audience,
            'event' => $campaign->event ? ['id' => $campaign->event->id, 'title' => $campaign->event->title] : null,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'recipients_count' => 0, 'sent_count' => 0, 'failed_count' => 0, 'opened_count' => 0, 'clicked_count' => 0,
            ...$counts,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Campaign $campaign): array
    {
        $campaign->loadMissing('event:id,title');
        // Ventes rapportées : commandes payées arrivées par le bouton de la campagne, par devise.
        $sales = Order::paidSales()->where('campaign_id', $campaign->id)
            ->selectRaw('currency, count(*) as orders, sum(total) as revenue')->groupBy('currency')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'orders' => (int) $row->orders, 'revenue' => (int) $row->revenue]);

        return [
            ...$this->summary($campaign, $this->sender->counts([$campaign->id])[$campaign->id] ?? []),
            'body' => $campaign->body,
            'button_label' => $campaign->button_label,
            'event_id' => $campaign->event_id,
            'sales' => $sales->values(),
        ];
    }
}
