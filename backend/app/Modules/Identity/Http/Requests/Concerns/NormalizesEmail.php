<?php

namespace App\Modules\Identity\Http\Requests\Concerns;

/** Les e-mails sont enregistrés en minuscules : toute requête qui en reçoit un le normalise pareil. */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
