<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_link_points_to_the_website(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'grace@example.cd']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Grace@Example.cd'])->assertAccepted();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:3000/mot-de-passe/reinitialiser?token=');
        });
    }

    public function test_an_unknown_email_gets_the_same_answer(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'grace@example.cd']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'grace@example.cd'])->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'personne@example.cd'])->assertAccepted()->json();

        $this->assertSame($known, $unknown);
        Notification::assertCount(1);
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create();
        $user->createToken('ancien-appareil');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'NouveauSecret2026', 'password_confirmation' => 'NouveauSecret2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('NouveauSecret2026', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_invalid_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'faux', 'email' => $user->email,
            'password' => 'NouveauSecret2026', 'password_confirmation' => 'NouveauSecret2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'faux', 'email' => 'inconnu@example.cd',
            'password' => 'NouveauSecret2026', 'password_confirmation' => 'NouveauSecret2026',
        ])->assertJsonPath('errors.email.0', __('passwords.token'));
    }

    public function test_emails_use_the_user_language(): void
    {
        $this->assertSame('en', User::factory()->make(['locale' => 'en'])->preferredLocale());
    }
}
