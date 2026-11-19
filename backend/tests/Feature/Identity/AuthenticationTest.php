<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** Les requêtes du site portent son origine (Sanctum leur ouvre une session) et la langue choisie. */
    private const FROM_SITE = ['Referer' => 'http://localhost:3000/', 'Accept-Language' => 'fr'];

    private const PASSWORD = 'Kinshasa2026';

    public function test_an_organizer_registers_and_is_signed_in(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Grâce Mbuyi',
            'email' => ' Grace@Example.CD ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], self::FROM_SITE)
            ->assertCreated()
            ->assertJsonPath('data.email', 'grace@example.cd')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticated('web');

        // Requête suivante : l'utilisateur est retrouvé grâce à la session seule.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', self::FROM_SITE)->assertOk()->assertJsonPath('data.name', 'Grâce Mbuyi');
    }

    public function test_registration_keeps_the_chosen_language(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'John', 'email' => 'john@example.com', 'locale' => 'en',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], self::FROM_SITE)->assertCreated()->assertJsonPath('data.locale', 'en');
    }

    public function test_registration_rejects_weak_passwords_and_taken_emails_in_french(): void
    {
        User::factory()->create(['email' => 'pris@example.cd']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test', 'email' => 'pris@example.cd',
            'password' => 'court', 'password_confirmation' => 'court',
        ], self::FROM_SITE)->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->assertStringContainsString('adresse e-mail', $response->json('errors.email.0'));
        $this->assertStringContainsString('mot de passe', $response->json('errors.password.0'));
    }

    public function test_messages_switch_to_english_on_request(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [], [...self::FROM_SITE, 'Accept-Language' => 'en-US,en;q=0.9'])
            ->assertUnprocessable();

        $this->assertSame('The name field is required.', $response->json('errors.name.0'));
    }

    public function test_session_sign_in_is_only_for_the_website(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.cd', 'password' => self::PASSWORD])->assertStatus(400);
    }

    public function test_a_user_signs_in_and_out(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', ['email' => ' '.strtoupper($user->email), 'password' => self::PASSWORD], self::FROM_SITE)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/v1/auth/logout', [], self::FROM_SITE)->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_a_wrong_password_gives_a_generic_error(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'mauvais'], self::FROM_SITE)
            ->assertUnprocessable()
            ->json('errors.email.0');
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.cd', 'password' => 'mauvais'], self::FROM_SITE)
            ->json('errors.email.0');

        $this->assertSame($wrongPassword, $unknownEmail);
        $this->assertGuest('web');
    }

    public function test_sign_in_is_locked_after_five_failures(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'mauvais'], self::FROM_SITE)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], self::FROM_SITE)
            ->assertStatus(429);
        $this->assertGuest('web');
    }
}
