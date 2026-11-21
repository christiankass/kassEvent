<?php

namespace Tests\Feature\Events;

use App\Models\User;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Mail\TicketsMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private string $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();
        $this->organization = $this->createOrganization($this->owner = User::factory()->create(['name' => 'Christian K']));
        Sanctum::actingAs($this->owner);
        $this->event = $this->postJson('/api/v1/events', [
            'title' => 'Mariage de Grace & Josué', 'starts_at' => now()->addMonth()->format('Y-m-d').'T16:00',
            'venue_name' => 'Jardin du Fleuve', 'city' => 'Kinshasa',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id, 'Accept' => 'application/json', 'Accept-Language' => 'fr'];
    }

    private function design(array $overrides = []): array
    {
        return [
            'template' => 'mariage', 'kind' => 'invitation', 'primary_color' => '#6b1e3a', 'accent_color' => '#D4A24C',
            'font' => 'playfair', 'headline' => null, 'message' => "Tenue de soirée\nRéception à 19 h",
            'footer' => 'Une invitation par personne.', 'banner' => 'none', ...$overrides,
        ];
    }

    public function test_an_event_starts_with_the_kassevent_look_without_saving_anything(): void
    {
        $this->getJson("/api/v1/events/{$this->event}/design", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.template', 'kassevent')
            ->assertJsonPath('data.kind', 'ticket')
            ->assertJsonPath('data.primary_color', '#0B3B3C')
            ->assertJsonPath('data.headline_text', 'Billet d\'entrée')
            ->assertJsonPath('data.colors.on_primary', '#FFFFFF')
            ->assertJsonPath('meta.templates.mariage.kind', 'invitation');

        $this->assertSame(0, app(TenantContext::class)->run($this->organization->id, fn () => EventDesign::count()));
    }

    public function test_the_organizer_saves_an_invitation_look(): void
    {
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#6B1E3A')
            ->assertJsonPath('data.headline_text', 'Vous êtes invité(e)')
            // Texte lisible calculé : blanc sur bordeaux, encre sur or.
            ->assertJsonPath('data.colors.on_primary', '#FFFFFF')
            ->assertJsonPath('data.colors.on_accent', '#0E1B1C');

        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(['headline' => 'Nous nous marions !']), $this->headers())
            ->assertJsonPath('data.headline_text', 'Nous nous marions !');
        $this->assertSame(1, app(TenantContext::class)->run($this->organization->id, fn () => EventDesign::count()));
    }

    public function test_choices_are_limited_to_safe_values(): void
    {
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design([
            'primary_color' => 'red;background:url(x)', 'font' => 'Comic Sans', 'template' => 'autre', 'kind' => 'flyer',
            'message' => str_repeat('a', 281),
        ]), $this->headers())->assertJsonValidationErrors(['primary_color', 'font', 'template', 'kind', 'message']);
        // Espaces et retour à la ligne autour du code : retirés avant la validation, la colonne garde 7 caractères.
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(['accent_color' => "#ffffff\n"]), $this->headers())
            ->assertOk()->assertJsonPath('data.accent_color', '#FFFFFF');
    }

    public function test_a_banner_needs_an_image(): void
    {
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(['banner' => 'cover']), $this->headers())
            ->assertJsonValidationErrors('banner');
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(['banner' => 'custom']), $this->headers())
            ->assertJsonValidationErrors('banner');

        $this->post("/api/v1/events/{$this->event}/cover", ['cover' => UploadedFile::fake()->image('affiche.jpg', 1200, 630)], $this->headers())->assertOk();
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(['banner' => 'cover']), $this->headers())
            ->assertOk()->assertJsonPath('data.banner', 'cover');
        $this->assertNotNull($this->getJson("/api/v1/events/{$this->event}/design", $this->headers())->json('data.banner_url'));
    }

    public function test_logo_and_banner_are_stored_per_organization_and_replaced(): void
    {
        $upload = fn (string $image, UploadedFile $file) => $this->post("/api/v1/events/{$this->event}/design/{$image}", ['image' => $file], $this->headers());

        $first = $upload('logo', UploadedFile::fake()->image('logo.png', 200, 200))->assertOk()->json('data.logo_url');
        $this->assertStringContainsString("designs/{$this->organization->id}/", $first);
        $upload('logo', UploadedFile::fake()->image('logo2.png', 200, 200))->assertOk();
        $this->assertCount(1, Storage::disk('public')->allFiles("designs/{$this->organization->id}"));

        $upload('banner', UploadedFile::fake()->image('bandeau.jpg', 1200, 300))->assertOk()->assertJsonPath('data.banner', 'custom');
        $this->assertCount(2, Storage::disk('public')->allFiles("designs/{$this->organization->id}"));

        // Jamais de SVG ni de fichier qui n'est pas une image.
        $upload('logo', UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml'))->assertJsonValidationErrors('image');
        $upload('logo', UploadedFile::fake()->create('virus.php', 2, 'application/x-php'))->assertJsonValidationErrors('image');

        $this->deleteJson("/api/v1/events/{$this->event}/design/banner", [], $this->headers())
            ->assertOk()->assertJsonPath('data.banner', 'none')->assertJsonPath('data.banner_url', null);
        $this->assertCount(1, Storage::disk('public')->allFiles("designs/{$this->organization->id}"));
    }

    public function test_deleting_a_draft_event_removes_its_design_images(): void
    {
        $this->post("/api/v1/events/{$this->event}/design/logo", ['image' => UploadedFile::fake()->image('logo.png', 200, 200)], $this->headers())->assertOk();

        $this->deleteJson("/api/v1/events/{$this->event}", [], $this->headers())->assertNoContent();

        $this->assertSame([], Storage::disk('public')->allFiles("designs/{$this->organization->id}"));
    }

    public function test_the_email_preview_shows_the_design_with_texts_escaped(): void
    {
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(['message' => '<script>alert(1)</script>']), $this->headers())->assertOk();

        $preview = $this->getJson("/api/v1/events/{$this->event}/design/preview", $this->headers())->assertOk();

        $html = $preview->json('data.html');
        $this->assertSame('Votre invitation pour Mariage de Grace & Josué', $preview->json('data.subject'));
        $this->assertStringContainsString('Vous êtes invité(e)', $html);
        $this->assertStringContainsString('#6B1E3A', $html);
        $this->assertStringContainsString('Playfair Display', $html);
        $this->assertStringContainsString('APER-CU0E-XEMP', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_sample_email_goes_only_to_the_signed_in_user(): void
    {
        $this->postJson("/api/v1/events/{$this->event}/design/test", [], $this->headers())
            ->assertOk()->assertJsonPath('message', "E-mail d'exemple envoyé à {$this->owner->email}.");

        Mail::assertSent(TicketsMail::class, fn (TicketsMail $mail) => $mail->hasTo($this->owner->email) && count($mail->to) === 1);
    }

    public function test_a_viewer_sees_the_design_but_cannot_change_it(): void
    {
        $viewer = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($viewer) {
            $this->organization->users()->attach($viewer->id, ['status' => Membership::ACTIVE]);
            $viewer->assignRole('viewer');
        });
        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/events/{$this->event}/design", $this->headers())->assertOk();
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(), $this->headers())->assertForbidden();
        $this->post("/api/v1/events/{$this->event}/design/logo", ['image' => UploadedFile::fake()->image('logo.png', 200, 200)], $this->headers())->assertForbidden();
        $this->postJson("/api/v1/events/{$this->event}/design/test", [], $this->headers())->assertForbidden();
    }

    public function test_another_organization_never_reaches_the_design(): void
    {
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(), $this->headers())->assertOk();
        $other = $this->createOrganization($stranger = User::factory()->create(), 'Autre agence');
        Sanctum::actingAs($stranger);
        app(TenantContext::class)->clear();

        $this->getJson("/api/v1/events/{$this->event}/design", $this->headers($other))->assertNotFound();
        $this->getJson("/api/v1/events/{$this->event}/design/preview", $this->headers($other))->assertNotFound();
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(), $this->headers($other))->assertNotFound();
        $this->post("/api/v1/events/{$this->event}/design/logo", ['image' => UploadedFile::fake()->image('logo.png', 200, 200)], $this->headers($other))->assertNotFound();
        $this->deleteJson("/api/v1/events/{$this->event}/design/logo", [], $this->headers($other))->assertNotFound();

        // Second verrou : même sans le filtre Eloquent, la base ne montre pas la ligne.
        $this->assertSame(0, app(TenantContext::class)->run($other->id, fn () => EventDesign::withoutGlobalScopes()->count()));
    }

    public function test_tickets_sold_use_the_design_on_the_order_page_and_in_the_email(): void
    {
        $this->putJson("/api/v1/events/{$this->event}/design", $this->design(), $this->headers())->assertOk();
        $ticket = $this->postJson("/api/v1/events/{$this->event}/ticket-types", ['name' => 'Invité', 'price' => '0', 'quantity' => 100], $this->headers())->json('data.id');
        $slug = $this->postJson("/api/v1/events/{$this->event}/publish", [], $this->headers())->assertOk()->json('data.slug');
        app(TenantContext::class)->clear();
        auth()->forgetGuards();

        $this->postJson("/api/v1/public/events/{$slug}/orders", [
            'items' => [['ticket_type_id' => $ticket, 'quantity' => 1]], 'name' => 'Maman Thérèse', 'email' => 'therese@example.com', 'payment' => null,
        ])->assertCreated()
            ->assertJsonPath('data.design.kind', 'invitation')
            ->assertJsonPath('data.design.primary_color', '#6B1E3A')
            ->assertJsonPath('data.design.message', "Tenue de soirée\nRéception à 19 h");

        // L'acheteur a commandé en anglais : l'e-mail suit sa langue, avec le vocabulaire de l'invitation.
        Mail::assertSent(TicketsMail::class, function (TicketsMail $mail) {
            $html = app(TenantContext::class)->run($this->organization->id, fn () => $mail->render());

            return $mail->envelope()->subject === 'Your invitation to Mariage de Grace & Josué'
                && str_contains($html, 'Guest:') && str_contains($html, 'Tenue de soirée<br') && str_contains($html, '#6B1E3A');
        });
    }
}
