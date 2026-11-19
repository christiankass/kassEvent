<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const FROM_SITE = ['Referer' => 'http://localhost:3000/', 'Accept-Language' => 'fr'];

    private const PASSWORD = 'Kinshasa2026';

    private function code(string $secret, int $offsetSeconds = 0): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, (int) floor((time() + $offsetSeconds) / 30));
    }

    /** Active la double authentification et renvoie [utilisateur, secret, codes de secours]. */
    private function enabledUser(): array
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($user, 'web');

        $secret = $this->postJson('/api/v1/auth/two-factor', ['password' => self::PASSWORD], self::FROM_SITE)
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr_svg']])
            ->json('data.secret');

        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->code($secret)], self::FROM_SITE)
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes')
            ->json('data.recovery_codes');

        $this->postJson('/api/v1/auth/logout', [], self::FROM_SITE)->assertNoContent();
        $this->app['auth']->forgetGuards();

        return [$user->fresh(), $secret, $codes];
    }

    private function login(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], self::FROM_SITE)
            ->assertOk()
            ->assertExactJson(['two_factor' => true]);
        $this->assertGuest('web');
    }

    public function test_enabling_requires_the_password_and_a_first_code(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/auth/two-factor', ['password' => 'faux'], self::FROM_SITE)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/two-factor', ['password' => self::PASSWORD], self::FROM_SITE)->assertOk();
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'], self::FROM_SITE)->assertJsonValidationErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->getJson('/api/v1/me', self::FROM_SITE)->assertJsonPath('data.two_factor_enabled', false);
    }

    public function test_the_secret_is_stored_encrypted_and_never_shown_again(): void
    {
        [$user, $secret] = $this->enabledUser();

        $this->assertNotSame($secret, $user->getRawOriginal('two_factor_secret'));
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/me', self::FROM_SITE)
            ->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonMissingPath('data.two_factor_secret');
    }

    public function test_login_waits_for_the_code(): void
    {
        [$user, $secret] = $this->enabledUser();
        $this->login($user);

        $this->getJson('/api/v1/me', self::FROM_SITE)->assertUnauthorized();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'], self::FROM_SITE)->assertJsonValidationErrors('code');

        // Le code déjà utilisé à l'activation est refusé : on prend le suivant (marge de 30 s acceptée).
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->code($secret, 30)], self::FROM_SITE)
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->enabledUser();
        $code = $this->code($secret, 30); // pas celui déjà utilisé à l'activation
        $this->login($user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code], self::FROM_SITE)->assertOk();

        $this->postJson('/api/v1/auth/logout', [], self::FROM_SITE);
        $this->app['auth']->forgetGuards();
        $this->login($user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code], self::FROM_SITE)->assertJsonValidationErrors('code');
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$user, , $codes] = $this->enabledUser();
        $this->login($user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => strtolower($codes[0])], self::FROM_SITE)->assertOk();

        $this->postJson('/api/v1/auth/logout', [], self::FROM_SITE);
        $this->app['auth']->forgetGuards();
        $this->login($user);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $codes[0]], self::FROM_SITE)
            ->assertJsonValidationErrors('recovery_code');
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_the_challenge_needs_a_recent_password_step(): void
    {
        [$user, $secret] = $this->enabledUser();

        // Sans étape du mot de passe : rien à valider.
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->code($secret)], self::FROM_SITE)
            ->assertJsonValidationErrors('code');

        $this->login($user);
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->code($secret)], self::FROM_SITE)
            ->assertJsonValidationErrors('code');
        $this->assertGuest('web');
    }

    public function test_too_many_wrong_codes_end_the_attempt(): void
    {
        [$user, $secret] = $this->enabledUser();
        $this->login($user);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'], self::FROM_SITE);
        }

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->code($secret)], self::FROM_SITE)->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_disabling_requires_the_password(): void
    {
        [$user] = $this->enabledUser();
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'faux'], self::FROM_SITE)->assertJsonValidationErrors('password');
        $this->deleteJson('/api/v1/auth/two-factor', ['password' => self::PASSWORD], self::FROM_SITE)->assertNoContent();

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        [$user, , $codes] = $this->enabledUser();
        $this->actingAs($user, 'web');

        $fresh = $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => self::PASSWORD], self::FROM_SITE)
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertEmpty(array_intersect($codes, $fresh));
    }
}
