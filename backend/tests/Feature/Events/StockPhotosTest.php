<?php

namespace Tests\Feature\Events;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private string $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.pexels.key' => 'pexels-key', 'services.unsplash.key' => null, 'services.stock_photos.source' => 'pexels']);
        $this->organization = $this->createOrganization($owner = User::factory()->create());
        Sanctum::actingAs($owner);
        $this->event = $this->postJson('/api/v1/events', [
            'title' => 'Mariage de Grace', 'starts_at' => now()->addMonth()->format('Y-m-d').'T18:00',
            'venue_name' => 'Salle Fleuve', 'city' => 'Kinshasa',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id, 'Accept' => 'application/json', 'Accept-Language' => 'fr'];
    }

    private function pexelsPhoto(int $id): array
    {
        return [
            'id' => $id, 'width' => 4000, 'height' => 6000, 'avg_color' => '#6B4A3A', 'alt' => 'Couple de mariés',
            'url' => "https://www.pexels.com/photo/{$id}/", 'photographer' => 'Nathalie M.', 'photographer_url' => 'https://www.pexels.com/@nathalie',
            'src' => ['original' => "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg"],
        ];
    }

    private function jpeg(): string
    {
        $file = UploadedFile::fake()->image('photo.jpg', 1200, 1600);

        return (string) file_get_contents($file->getRealPath());
    }

    public function test_the_studio_searches_the_photo_library_with_the_server_key_and_caches_it(): void
    {
        Http::fake(['api.pexels.com/v1/search*' => Http::response(['photos' => [$this->pexelsPhoto(101), $this->pexelsPhoto(102)]])]);

        $response = $this->getJson('/api/v1/stock-photos?q=african+wedding+couple', $this->headers())
            ->assertOk()->assertJsonPath('meta.enabled', true)->assertJsonPath('meta.source', 'pexels')
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', '101')->assertJsonPath('data.0.author', 'Nathalie M.');
        $this->assertStringStartsWith('https://images.pexels.com/photos/101/', $response->json('data.0.thumb'));
        $this->assertArrayNotHasKey('full', $response->json('data.0'));
        $this->getJson('/api/v1/stock-photos?q=African  wedding couple', $this->headers())->assertOk()->assertJsonCount(2, 'data');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'pexels-key') && $request['query'] === 'african wedding couple');
    }

    public function test_without_a_key_the_library_is_disabled(): void
    {
        config(['services.pexels.key' => null]);
        Http::fake();

        $this->getJson('/api/v1/stock-photos?q=wedding', $this->headers())
            ->assertOk()->assertJsonPath('meta.enabled', false)->assertJsonPath('data', []);
        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'pexels', 'id' => '101'], $this->headers())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_library_photo_becomes_an_event_image_with_its_credit(): void
    {
        Http::fake([
            'api.pexels.com/v1/photos/101' => Http::response($this->pexelsPhoto(101)),
            'images.pexels.com/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $name = $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'pexels', 'id' => '101'], $this->headers())
            ->assertCreated()->assertJsonPath('data.credit.author', 'Nathalie M.')->assertJsonPath('data.credit.source', 'pexels')
            ->json('data.name');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.jpg$/', $name);
        $directory = app(TenantContext::class)->run($this->organization->id, fn () => EventDesign::studioDirectory(Event::findOrFail($this->event)));
        Storage::disk('public')->assertExists($directory.'/'.$name);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://images.pexels.com/photos/101/') && ! $request->hasHeader('Authorization'));
    }

    public function test_only_photos_from_the_library_hosts_are_downloaded(): void
    {
        Http::fake([
            'api.pexels.com/v1/photos/7' => Http::response([...$this->pexelsPhoto(7), 'src' => ['original' => 'http://169.254.169.254/latest/meta-data']]),
            '*' => Http::response($this->jpeg()),
        ]);

        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'pexels', 'id' => '7'], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'pexels', 'id' => '../../photos'], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'unsplash', 'id' => 'abcdef1'], $this->headers())->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_unsplash_photos_credit_the_author_skip_paid_photos_and_count_the_download(): void
    {
        config(['services.pexels.key' => null, 'services.unsplash.key' => 'unsplash-key']);
        $photo = fn (string $id, bool $premium = false) => [
            'id' => $id, 'width' => 3000, 'height' => 4500, 'color' => '#202020', 'alt_description' => 'choir', 'premium' => $premium,
            'urls' => ['raw' => "https://images.unsplash.com/photo-{$id}?ixid=abc"],
            'links' => ['html' => "https://unsplash.com/photos/{$id}", 'download_location' => "https://api.unsplash.com/photos/{$id}/download?ixid=abc"],
            'user' => ['name' => 'Joel K.', 'links' => ['html' => 'https://unsplash.com/@joel']],
        ];
        Http::fake([
            'api.unsplash.com/search/photos*' => Http::response(['results' => [$photo('Abc123xyz'), $photo('Paid12345', true)]]),
            'api.unsplash.com/photos/Abc123xyz/download*' => Http::response(['url' => 'x']),
            'api.unsplash.com/photos/Abc123xyz' => Http::response($photo('Abc123xyz')),
            'images.unsplash.com/*' => Http::response($this->jpeg()),
        ]);

        $this->getJson('/api/v1/stock-photos?q=gospel+choir', $this->headers())
            ->assertOk()->assertJsonPath('meta.source', 'unsplash')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author_url', 'https://unsplash.com/@joel?utm_source=kassevent&utm_medium=referral');
        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'unsplash', 'id' => 'Abc123xyz'], $this->headers())
            ->assertCreated()->assertJsonPath('data.credit.author', 'Joel K.');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/photos/Abc123xyz/download') && $request->hasHeader('Authorization', 'Client-ID unsplash-key'));
    }

    public function test_the_library_is_for_organizers_who_manage_events_and_imports_stay_in_their_organization(): void
    {
        Http::fake(['*' => Http::response($this->pexelsPhoto(101))]);
        $other = $this->createOrganization($stranger = User::factory()->create());
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'pexels', 'id' => '101'], $this->headers($other))->assertNotFound();

        $viewer = User::factory()->create();
        app(TenantContext::class)->run($this->organization->id, function () use ($viewer) {
            $this->organization->users()->attach($viewer->id, ['status' => Membership::ACTIVE]);
            $viewer->assignRole('viewer');
        });
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/stock-photos?q=wedding', $this->headers())->assertForbidden();
        $this->postJson("/api/v1/events/{$this->event}/studio/assets/stock", ['source' => 'pexels', 'id' => '101'], $this->headers())->assertForbidden();
    }
}
