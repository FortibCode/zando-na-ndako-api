<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'email est présenté comme optionnel côté formulaire admin (et validé comme
 * `nullable` côté API) mais la colonne était NOT NULL depuis la migration
 * initiale — toute création d'utilisateur sans email plantait avec une erreur
 * SQL brute au lieu d'un message de validation propre.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};
