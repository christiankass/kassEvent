<?php

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Audience\Support\ContactBook;
use App\Modules\Campaigns\Mail\CampaignMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Support\CampaignSender;
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
    public function __construct(private readonly ContactBook $contacts, private readonly CampaignSender $sender) {}

    public function index(): JsonResponse
    {
        $campaigns = Campaign::with('event:id,title')->latest()->limit(100)->get();

        return response()->json([
            'data' => $campaigns->map(fn (Campaign $campaign) => $this->summary($campaign)),
            'audiences' => $this->contacts->emailAudienceCounts(),
            // Sans service d'envoi configuré, les e-mails restent dans le journal du serveur.
            'demo' => in_array(config('mail.default'), ['log', 'array'], true),
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
        $campaign = $this->whileEditable($id, fn (Campaign $campaign) => $campaign->fill($data)->save());

        return response()->json(['data' => $this->detail($campaign)]);
    }

    public function destroy(string $id): Response
    {
        $this->whileEditable($id, fn (Campaign $campaign) => $campaign->delete());

        return response()->noContent();
    }

    /** Envoi tout de suite, ou programmé (scheduled_at). */
    public function send(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(90)->toDateTimeString()],
        ]);
        if ($campaign->event_id !== null && ! $campaign->event?->isPublished()) {
            throw ValidationException::withMessages(['event_id' => __('campaigns.event_unpublished')]);
        }
        if ($this->contacts->emailAudience($campaign->audience)->doesntExist()) {
            throw ValidationException::withMessages(['audience' => __('campaigns.no_recipients')]);
        }

        if (isset($data['scheduled_at'])) {
            $this->whileEditable($id, fn (Campaign $locked) => $locked->forceFill(['status' => Campaign::SCHEDULED, 'scheduled_at' => $data['scheduled_at']])->save());
        } elseif (! $this->sender->start($campaign)) {
            throw ValidationException::withMessages(['status' => __('campaigns.locked')]);
        }

        return response()->json(['data' => $this->detail($campaign->refresh())]);
    }

    /** Programmée : revient en brouillon. */
    public function cancel(string $id): JsonResponse
    {
        $campaign = DB::transaction(function () use ($id) {
            $campaign = Campaign::lockForUpdate()->findOrFail($id);
            if ($campaign->status !== Campaign::SCHEDULED) {
                throw ValidationException::withMessages(['status' => __('campaigns.not_scheduled')]);
            }

            $campaign->forceFill(['status' => Campaign::DRAFT, 'scheduled_at' => null])->save();

            return $campaign;
        });

        return response()->json(['data' => $this->detail($campaign)]);
    }

    /** Essai à l'adresse de la personne connectée, pour voir l'e-mail dans sa propre messagerie. */
    public function test(Request $request, string $id): JsonResponse
    {
        $campaign = Campaign::with(['organization', 'event'])->findOrFail($id);
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
     * Change une campagne encore modifiable, sous verrou : le lanceur de campagnes programmées ne peut
     * pas la faire partir entre la vérification et l'écriture.
     *
     * @param  callable(Campaign): mixed  $change
     */
    private function whileEditable(string $id, callable $change): Campaign
    {
        return DB::transaction(function () use ($id, $change) {
            $campaign = Campaign::lockForUpdate()->findOrFail($id);
            if (! $campaign->isEditable()) {
                throw ValidationException::withMessages(['status' => __('campaigns.locked')]);
            }
            $change($campaign);

            return $campaign;
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'event_id' => ['nullable', 'uuid'],
            'button_label' => ['nullable', 'string', 'max:40'],
            'audience' => ['required', Rule::in(Campaign::AUDIENCES)],
        ]);
        // Événement de l'organisation seulement (le scope ne voit pas ceux des autres).
        if (($data['event_id'] ?? null) !== null && ! Event::whereKey($data['event_id'])->exists()) {
            throw ValidationException::withMessages(['event_id' => __('validation.exists', ['attribute' => 'event'])]);
        }

        return [...$data, 'subject' => trim($data['subject']), 'button_label' => trim((string) ($data['button_label'] ?? '')) ?: null];
    }

    /** @return array<string, mixed> */
    private function summary(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'subject' => $campaign->subject,
            'status' => $campaign->status,
            'audience' => $campaign->audience,
            'event' => $campaign->event ? ['id' => $campaign->event->id, 'title' => $campaign->event->title] : null,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'recipients_count' => $campaign->recipients_count,
            'sent_count' => $campaign->sent_count,
            'failed_count' => $campaign->failed_count,
            'opened_count' => $campaign->opened_count,
            'clicked_count' => $campaign->clicked_count,
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
            ...$this->summary($campaign),
            'body' => $campaign->body,
            'button_label' => $campaign->button_label,
            'event_id' => $campaign->event_id,
            'sales' => $sales->values(),
        ];
    }
}
