<?php

use App\Modules\Access\Roles;
use Illuminate\Database\Migrations\Migration;

/** Données de référence : les rôles prédéfinis existent dans toute base, y compris en production. */
return new class extends Migration
{
    public function up(): void
    {
        Roles::sync();
    }

    public function down(): void
    {
        // Les tables sont supprimées par la migration des permissions.
    }
};
