<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordResetController
{
    /**
     * Envoie le lien si le compte existe. La réponse est la même dans tous les cas, et le travail
     * (jeton, e-mail) se fait après l'envoi de la réponse : son délai ne révèle pas si l'adresse est inscrite.
     */
    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        $credentials = $request->only('email');
        dispatch(fn () => Password::sendResetLink($credentials))->afterResponse();

        return response()->json(['message' => __('identity.reset_link_sent')], 202);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        // Même message que le compte existe ou non : lien invalide, expiré ou adresse inconnue.
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('passwords.token')]);
        }

        return response()->json(['message' => __($status)]);
    }
}
