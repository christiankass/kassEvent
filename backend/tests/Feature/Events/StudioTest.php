<?php

namespace Tests\Feature\Events;

use App\Models\User;
use App\Modules\Events\Models\Event;
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

class StudioTest extends TestCase
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
            'title' => 'Concert Fally', 'starts_at' => now()->addMonth()->format('Y-m-d').'T20:00',
            'venue_name' => 'Stade des Martyrs', 'city' => 'Kinshasa',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id, 'Accept' => 'application/json', 'Accept-Language' => 'fr'];
    }

    /** Billet valide : fond, titre, photo, nom de l'invité et QR code. */
    private function ticket(array $elements = [], ?array $qr = null): array
    {
        return [
            'width' => 1080, 'height' => 1350, 'background' => ['color' => '#0b3b3c', 'image' => null],
            'elements' => [
                ['id' => 'title', 'type' => 'text', 'x' => 80, 'y' => 80, 'width' => 920, 'height' => 120, 'rotation' => 0,
                    'text' => 'Concert <b>Fally</b>', 'font' => 'fraunces', 'fontSize' => 96, 'bold' => true, 'fill' => '#ffffff', 'align' => 'center'],
                ['id' => 'photo', 'type' => 'image', 'x' => 80, 'y' => 240, 'width' => 920, 'height' => 500, 'rotation' => 0, 'asset' => null],
                ['id' => 'holder', 'type' => 'field', 'field' => 'holder', 'x' => 80, 'y' => 800, 'width' => 600, 'height' => 60, 'rotation' => 3,
                    'font' => 'instrument', 'fontSize' => 44, 'fill' => '#ffffff'],
                $qr ?? ['id' => 'qr', 'type' => 'qr', 'x' => 700, 'y' => 970, 'width' => 300, 'height' => 300, 'rotation' => 0],
                ...$elements,
            ],
        ];
    }

    private function save(array $document, string $usage = 'ticket', ?Organization $organization = null)
    {
        [$width, $height] = [$document['width'], $document['height']];

        return $this->post("/api/v1/events/{$this->event}/studio/{$usage}", [
            'canvas' => json_encode($document),
            'image' => UploadedFile::fake()->image('page.jpg', $width, $height),
        ], $this->headers($organization));
    }

    public function test_the_organizer_designs_a_ticket_and_it_replaces_the_simple_ticket(): void
    {
        $this->getJson("/api/v1/events/{$this->event}/studio", $this->headers())
            ->assertOk()->assertJsonPath('data.mode', 'classic')->assertJsonPath('data.ticket', null)
            ->assertJsonPath('meta.formats.portrait', [1080, 1350])
            ->assertJsonPath('meta.usage_formats.ticket', ['portrait', 'square', 'story']);

        $this->save($this->ticket())
            ->assertOk()
            ->assertJsonPath('data.mode', 'canvas')
            ->assertJsonPath('data.ticket.background.color', '#0B3B3C')
            ->assertJsonPath('data.ticket.elements.0.text', 'Concert <b>Fally</b>')
            ->assertJsonPath('data.ticket.elements.2.field', 'holder');

        $this->getJson("/api/v1/events/{$this->event}/design", $this->headers())->assertJsonPath('data.mode', 'canvas');

        // Aperçu calculé par le serveur : une image au format du billet.
        $preview = $this->get("/api/v1/events/{$this->event}/studio/preview", $this->headers())->assertOk();
        $this->assertSame('image/jpeg', $preview->headers->get('Content-Type'));
        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring($preview->getContent()), 0, 2));

        // Enregistrer à nouveau remplace l'image fixe au lieu de l'accumuler.
        $this->save($this->ticket())->assertOk();
        $this->assertCount(1, Storage::disk('public')->files("designs/{$this->organization->id}/{$this->event}"));

        // Retour au billet simple : le design du studio est gardé.
        $this->postJson("/api/v1/events/{$this->event}/studio/classic", [], $this->headers())
            ->assertOk()->assertJsonPath('data.mode', 'classic')->assertJsonPath('data.ticket.elements.3.type', 'qr');
        $this->getJson("/api/v1/events/{$this->event}/design", $this->headers())->assertJsonPath('data.mode', 'classic');
    }

    public function test_the_qr_code_must_stay_scannable(): void
    {
        $qr = fn (array $overrides) => ['id' => 'qr', 'type' => 'qr', 'x' => 700, 'y' => 970, 'width' => 300, 'height' => 300, 'rotation' => 0, ...$overrides];

        $this->save($this->ticket(qr: $qr(['width' => 100, 'height' => 100])))->assertInvalid(['canvas' => 'au moins 162']);
        $this->save($this->ticket(qr: $qr(['rotation' => 45])))->assertInvalid('canvas');
        $this->save($this->ticket(qr: $qr(['x' => 900])))->assertInvalid('canvas');
        $this->save($this->ticket([$qr(['id' => 'qr2', 'x' => 80])]))->assertInvalid('canvas');
        $doc = $this->ticket();
        array_splice($doc['elements'], 3, 1);
        $this->save($doc)->assertInvalid('canvas');
    }

    public function test_only_known_values_and_images_of_the_event_are_accepted(): void
    {
        $this->save($this->ticket([['type' => 'text', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'text' => 'a', 'font' => 'comic', 'fill' => 'red']]))->assertInvalid('canvas');
        $this->save($this->ticket([['type' => 'script', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]))->assertInvalid('canvas');
        $this->save($this->ticket([['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset' => 'https://evil.test/a.png']]))->assertInvalid('canvas');
        $this->save($this->ticket([['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset' => str_repeat('a', 40).'.png']]))->assertInvalid('canvas');
        $this->post("/api/v1/events/{$this->event}/studio/ticket", ['canvas' => json_encode($this->ticket()), 'image' => UploadedFile::fake()->image('page.jpg', 500, 500)], $this->headers())
            ->assertInvalid('image');

        // Une photo ajoutée dans l'éditeur, elle, est acceptée et relue à travers l'API.
        $name = $this->post("/api/v1/events/{$this->event}/studio/assets", ['image' => UploadedFile::fake()->image('photo.jpg', 3000, 1500)], $this->headers())
            ->assertCreated()->json('data.name');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.jpg$/', $name);
        $this->assertSame(2000, getimagesizefromstring(Storage::disk('public')->get("designs/{$this->organization->id}/{$this->event}/{$name}"))[0]);
        $this->get("/api/v1/events/{$this->event}/studio/assets/{$name}", $this->headers())->assertOk();
        $this->save($this->ticket([['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset' => $name]]))->assertOk();
        $this->get("/api/v1/events/{$this->event}/studio/assets/..%2F..%2Fsecret.png", $this->headers())->assertNotFound();
    }

    public function test_the_poster_becomes_the_event_cover_and_has_no_ticket_fields(): void
    {
        $poster = ['width' => 1200, 'height' => 630, 'background' => ['color' => '#F2B705', 'image' => null], 'elements' => [
            ['type' => 'text', 'x' => 60, 'y' => 60, 'width' => 1000, 'height' => 100, 'text' => 'Concert Fally', 'font' => 'playfair', 'fontSize' => 80],
        ]];
        $this->save([...$poster, 'elements' => [...$poster['elements'], ['type' => 'qr', 'x' => 0, 'y' => 0, 'width' => 200, 'height' => 200]]], 'poster')->assertInvalid('canvas');

        $this->save($poster, 'poster')->assertOk()->assertJsonPath('data.poster.background.color', '#F2B705');
        $cover = app(TenantContext::class)->run($this->organization->id, fn () => Event::sole()->cover_path);
        Storage::disk('public')->assertExists($cover);
        $this->get("/api/v1/events/{$this->event}/studio/assets/cover", $this->headers())->assertOk();

        $this->save($poster, 'poster')->assertOk();
        Storage::disk('public')->assertMissing($cover);
    }

    public function test_square_story_and_portrait_pages_icons_and_new_fonts(): void
    {
        // Billet carré : QR code et nom écrits par le serveur avec une police ajoutée (Anton).
        $square = $this->ticket([
            ['type' => 'icon', 'icon' => 'calendar', 'x' => 80, 'y' => 900, 'width' => 60, 'height' => 60, 'fill' => '#f2b705'],
            ['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 300, 'asset' => null, 'fit' => 'contain'],
        ], ['id' => 'qr', 'type' => 'qr', 'x' => 700, 'y' => 700, 'width' => 300, 'height' => 300, 'rotation' => 0]);
        $square['height'] = 1080;
        $square['elements'][2] = [...$square['elements'][2], 'font' => 'anton', 'bold' => true];
        $this->save($square)->assertOk()
            ->assertJsonPath('data.ticket.height', 1080)
            ->assertJsonPath('data.ticket.elements.4.icon', 'calendar')
            ->assertJsonPath('data.ticket.elements.5.fit', 'contain');
        $preview = $this->get("/api/v1/events/{$this->event}/studio/preview", $this->headers())->assertOk();
        $this->assertSame([1080, 1080], array_slice(getimagesizefromstring($preview->getContent()), 0, 2));

        // Affiche portrait et story : la page de vente reçoit l'image à ce format.
        foreach ([[1080, 1350], [1080, 1920]] as [$width, $height]) {
            $this->save(['width' => $width, 'height' => $height, 'background' => ['color' => '#123456', 'image' => null], 'elements' => []], 'poster')
                ->assertOk()->assertJsonPath('data.poster.height', $height);
        }

        // Format inconnu, paysage pour un billet, image d'un autre format, pictogramme ou cadrage inconnu : refusés.
        $this->save([...$this->ticket(), 'width' => 900, 'height' => 900])->assertInvalid('canvas');
        $this->save([...$this->ticket(), 'width' => 1200, 'height' => 630])->assertInvalid('canvas');
        $this->post("/api/v1/events/{$this->event}/studio/ticket", ['canvas' => json_encode($square), 'image' => UploadedFile::fake()->image('page.jpg', 1080, 1350)], $this->headers())
            ->assertInvalid('image');
        $this->save($this->ticket([['type' => 'icon', 'icon' => 'skull', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]))->assertInvalid('canvas');
        $this->save($this->ticket([['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'asset' => null, 'fit' => 'stretch']]))->assertInvalid('canvas');
    }

    public function test_a_viewer_sees_the_studio_but_cannot_change_it(): void
    {
        $viewer = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($viewer) {
            $this->organization->users()->attach($viewer->id, ['status' => Membership::ACTIVE]);
            $viewer->assignRole('viewer');
        });
        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/events/{$this->event}/studio", $this->headers())->assertOk();
        $this->save($this->ticket())->assertForbidden();
        $this->post("/api/v1/events/{$this->event}/studio/assets", ['image' => UploadedFile::fake()->image('a.png', 20, 20)], $this->headers())->assertForbidden();
        $this->postJson("/api/v1/events/{$this->event}/studio/classic", [], $this->headers())->assertForbidden();
    }

    public function test_another_organization_never_reaches_the_studio(): void
    {
        $this->save($this->ticket())->assertOk();
        $other = $this->createOrganization($stranger = User::factory()->create(), 'Autre agence');
        Sanctum::actingAs($stranger);
        app(TenantContext::class)->clear();

        $this->getJson("/api/v1/events/{$this->event}/studio", $this->headers($other))->assertNotFound();
        $this->get("/api/v1/events/{$this->event}/studio/preview", $this->headers($other))->assertNotFound();
        $this->get("/api/v1/events/{$this->event}/studio/assets/cover", $this->headers($other))->assertNotFound();
        $this->save($this->ticket(), 'ticket', $other)->assertNotFound();
        $this->post("/api/v1/events/{$this->event}/studio/assets", ['image' => UploadedFile::fake()->image('a.png', 20, 20)], $this->headers($other))->assertNotFound();
        $this->assertSame(0, app(TenantContext::class)->run($other->id, fn () => EventDesign::withoutGlobalScopes()->count()));
    }

    public function test_each_guest_gets_their_own_ticket_image_by_email_and_online(): void
    {
        $this->save($this->ticket())->assertOk();
        $type = $this->postJson("/api/v1/events/{$this->event}/ticket-types", ['name' => 'VIP', 'price' => '0', 'quantity' => 100], $this->headers())->json('data.id');
        $slug = $this->postJson("/api/v1/events/{$this->event}/publish", [], $this->headers())->assertOk()->json('data.slug');
        app(TenantContext::class)->clear();
        auth()->forgetGuards();

        $order = $this->postJson("/api/v1/public/events/{$slug}/orders", [
            'items' => [['ticket_type_id' => $type, 'quantity' => 1]], 'name' => 'Maman Thérèse', 'email' => 'therese@example.com', 'payment' => null,
        ])->assertCreated()->assertJsonPath('data.design.mode', 'canvas');
        [$id, $key, $code] = [$order->json('data.id'), $order->json('access_key'), $order->json('data.tickets.0.code')];

        $image = $this->get("/api/v1/public/orders/{$id}/tickets/{$code}?key={$key}")->assertOk();
        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring($image->getContent()), 0, 2));
        $this->get("/api/v1/public/orders/{$id}/tickets/{$code}?key=wrong")->assertNotFound();
        $this->get("/api/v1/public/orders/{$id}/tickets/AAAA-BBBB-CCCC?key={$key}")->assertNotFound();

        Mail::assertSent(TicketsMail::class, function (TicketsMail $mail) {
            $html = app(TenantContext::class)->run($this->organization->id, fn () => $mail->render());

            // Une image de billet complète (avec QR et nom), plus le QR seul de la carte simple.
            return str_contains($html, 'data:image/jpeg;base64') && ! str_contains($html, 'data:image/png;base64');
        });
    }
}
