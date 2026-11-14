<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/** Écriture sur une table métier sans organisation courante, ou vers une autre organisation. */
final class MissingTenantContext extends LogicException
{
    public static function forWrite(string $model): self
    {
        return new self("Aucune organisation courante pour écrire {$model}.");
    }

    public static function mismatch(string $model): self
    {
        return new self("{$model} n'appartient pas à l'organisation courante.");
    }
}
