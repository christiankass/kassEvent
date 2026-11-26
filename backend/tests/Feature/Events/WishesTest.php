<?php

namespace Tests\Feature\Events;

use App\Models\User;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WishesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private string $eventId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner, 'Anniversaire de Christian', 'individual');
        $this->actAsOwner();
        // Brouillon : le livre de vœux n'a pas besoin d'une page de vente publiée.
        $this->eventId = $this->postJson('/api/v1/events', [
            'title' => 'Anniversaire de Christian', 'starts_at' => now()->addDay()->format('Y-m-d').'T18:00',
            'venue_name' => 'À la maison', 'city' => 'Kinshasa',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    private function actAsOwner(?User $user = null): void
    {
        app(TenantContext::class)->clear();
        Sanctum::actingAs($user ?? $this->owner);
    }

    private function asVisitor(): void
    {
        app(TenantContext::class)->clear();
        auth()->forgetGuards();
    }

    /** @return array<string, string> */
    private function headers(?Organization $organization = null): array
    {
        return ['X-Organization-Id' => ($organization ?? $this->organization)->id];
    }

    private function openBook(array $settings = []): array
    {
        $this->actAsOwner();

        return $this->putJson("/api/v1/events/{$this->eventId}/wishes/settings", $settings, $this->headers())->assertOk()->json('book');
    }

    private function wish(string $bookId, array $data)
    {
        $this->asVisitor();

        return $this->postJson("/api/v1/public/wish-books/{$bookId}/wishes", $data);
    }

    private function ownerPage(): array
    {
        $this->actAsOwner();

        return $this->getJson("/api/v1/events/{$this->eventId}/wishes", $this->headers())->assertOk()->json();
    }

    public function test_the_book_is_closed_until_the_owner_opens_it(): void
    {
        $page = $this->ownerPage();
        $this->assertNull($page['book']);
        $this->assertSame([], $page['data']);

        $book = $this->openBook();
        $this->assertTrue($book['open']);
        $this->assertTrue($book['public']);
        $this->assertSame('celebration', $book['tone']);
        $this->assertStringEndsWith('/voeux/'.$book['id'], $book['url']);
        // Lien public aléatoire (UUID v4), pas un UUID ordonné dans le temps.
        $this->assertSame('4', $book['id'][14]);
        $this->assertSame($book['id'], $this->openBook()['id']);
    }

    public function test_anyone_with_the_link_can_leave_a_wish_without_an_account(): void
    {
        $book = $this->openBook();

        $this->wish($book['id'], ['name' => '  Maman  ', 'message' => "Joyeux anniversaire mon fils !\n\n\n\nQue Dieu te bénisse 🎂"])->assertCreated();

        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$book['id']}")->assertOk()
            ->assertJsonPath('data.event.title', 'Anniversaire de Christian')
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.wishes.0.name', 'Maman')
            ->assertJsonPath('data.wishes.0.message', "Joyeux anniversaire mon fils !\n\nQue Dieu te bénisse 🎂");

        $page = $this->ownerPage();
        $this->assertSame(1, $page['summary']['total']);
        $this->assertSame('Maman', $page['data'][0]['name']);
    }

    public function test_any_event_gets_its_tone_even_a_funeral(): void
    {
        $book = $this->openBook(['tone' => 'tribute']);
        $this->assertSame('tribute', $book['tone']);

        $this->wish($book['id'], ['name' => 'Famille Mbuyi', 'message' => 'Nos sincères condoléances. Que son âme repose en paix.'])->assertCreated();
        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$book['id']}")->assertOk()
            ->assertJsonPath('data.tone', 'tribute')
            ->assertJsonPath('data.wishes.0.name', 'Famille Mbuyi');

        // Le ton se change ensuite sans toucher aux messages ni aux autres réglages.
        $book = $this->openBook(['tone' => 'guestbook']);
        $this->assertSame('guestbook', $book['tone']);
        $this->assertTrue($book['open']);
        $this->assertSame(1, $this->ownerPage()['summary']['total']);

        $this->actAsOwner();
        $this->putJson("/api/v1/events/{$this->eventId}/wishes/settings", ['tone' => 'party'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('tone');
    }

    public function test_spam_is_held_back(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $book = $this->openBook();

        // Liens refusés, dans le nom comme dans le message.
        $this->wish($book['id'], ['name' => 'Promo', 'message' => 'Gagnez vite sur https://arnaque.example'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->wish($book['id'], ['name' => 'www.casino', 'message' => 'Bonne fête'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->wish($book['id'], ['name' => "\u{200B}\u{0007}", 'message' => 'Bonne fête'])->assertUnprocessable();
        $this->wish($book['id'], ['name' => 'A', 'message' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors(['name', 'message']);

        // Champ piège rempli (robot) et doublon : réponse normale, rien d'enregistré en plus.
        $this->wish($book['id'], ['name' => 'Robot', 'message' => 'Bonjour', 'website' => 'x'])->assertCreated();
        $this->wish($book['id'], ['name' => 'Tonton', 'message' => 'Bonne fête'])->assertCreated();
        $this->wish($book['id'], ['name' => 'Tonton', 'message' => 'Bonne fête'])->assertCreated();
        $this->assertSame(1, $this->ownerPage()['summary']['total']);
    }

    public function test_a_visitor_is_rate_limited(): void
    {
        $book = $this->openBook();
        foreach (range(1, 10) as $i) {
            $this->wish($book['id'], ['name' => "Ami {$i}", 'message' => 'Joyeux anniversaire'])->assertCreated();
        }
        $this->wish($book['id'], ['name' => 'Ami 11', 'message' => 'Joyeux anniversaire'])->assertTooManyRequests();
    }

    public function test_the_owner_hides_deletes_and_closes(): void
    {
        $book = $this->openBook();
        $this->wish($book['id'], ['name' => 'Cousin', 'message' => 'Message déplacé'])->assertCreated();
        $this->wish($book['id'], ['name' => 'Tata', 'message' => 'Joyeux anniversaire'])->assertCreated();
        $wishes = collect($this->ownerPage()['data'])->keyBy('name');

        $this->patchJson("/api/v1/wishes/{$wishes['Cousin']['id']}", ['hidden' => true], $this->headers())->assertOk()->assertJsonPath('data.hidden', true);
        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$book['id']}")->assertJsonPath('data.count', 1)->assertJsonCount(1, 'data.wishes');

        $this->actAsOwner();
        $this->deleteJson("/api/v1/wishes/{$wishes['Tata']['id']}", [], $this->headers())->assertNoContent();
        $this->assertSame(1, $this->ownerPage()['summary']['total']);

        // Vœux non affichés sur la page : seul l'organisateur les lit.
        $this->openBook(['public' => false]);
        $this->wish($book['id'], ['name' => 'Papa', 'message' => 'Fier de toi'])->assertCreated();
        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$book['id']}")->assertJsonPath('data.public', false)->assertJsonPath('data.wishes', []);

        // Livre fermé : plus de nouveaux vœux.
        $this->openBook(['open' => false]);
        $this->wish($book['id'], ['name' => 'Retard', 'message' => 'Bonne fête'])->assertUnprocessable();
    }

    public function test_unknown_links_and_other_organizations_get_404(): void
    {
        $book = $this->openBook();
        $this->wish($book['id'], ['name' => 'Tata', 'message' => 'Joyeux anniversaire'])->assertCreated();
        $wishId = $this->ownerPage()['data'][0]['id'];

        $this->asVisitor();
        $this->getJson('/api/v1/public/wish-books/'.Str::uuid())->assertNotFound();
        $this->postJson('/api/v1/public/wish-books/'.Str::uuid().'/wishes', ['name' => 'Tata', 'message' => 'Coucou'])->assertNotFound();

        $intruder = User::factory()->create();
        $other = $this->createOrganization($intruder, 'Autre', 'individual');
        $this->actAsOwner($intruder);
        $this->getJson("/api/v1/events/{$this->eventId}/wishes", $this->headers($other))->assertNotFound();
        $this->putJson("/api/v1/events/{$this->eventId}/wishes/settings", ['open' => false], $this->headers($other))->assertNotFound();
        $this->patchJson("/api/v1/wishes/{$wishId}", ['hidden' => true], $this->headers($other))->assertNotFound();
        $this->deleteJson("/api/v1/wishes/{$wishId}", [], $this->headers($other))->assertNotFound();

        // Événement supprimé : le lien ne mène plus nulle part.
        $this->actAsOwner();
        $this->deleteJson("/api/v1/events/{$this->eventId}", [], $this->headers())->assertSuccessful();
        $this->asVisitor();
        $this->getJson("/api/v1/public/wish-books/{$book['id']}")->assertNotFound();
    }
}
