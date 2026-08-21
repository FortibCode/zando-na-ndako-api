<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute un vrai statut de boutique (ouverte/pause/fermée) sur vendeurs : mobile laissait déjà le
 * vendeur choisir ce statut depuis l'écran "Statut de la boutique" ("En pause : vous ne recevez pas
 * de nouvelles commandes") mais aucune colonne n'existait pour le stocker et aucune règle ne
 * l'appliquait réellement côté serveur — un vendeur "en pause" continuait de recevoir des commandes.
 *
 * Sur PostgreSQL, Laravel crée les colonnes enum() comme VARCHAR + CHECK constraint : pour ajouter
 * ce type de colonne il faut poser la contrainte séparément (même pattern que
 * 2026_08_18_000004_extend_litiges_workflow.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeurs', function (Blueprint $table) {
            $table->string('statut_boutique')->default('ouverte')->after('statut_validation');
        });

        DB::statement("ALTER TABLE vendeurs DROP CONSTRAINT IF EXISTS vendeurs_statut_boutique_check");
        DB::statement("ALTER TABLE vendeurs ADD CONSTRAINT vendeurs_statut_boutique_check CHECK (statut_boutique IN ('ouverte', 'pause', 'fermee'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE vendeurs DROP CONSTRAINT IF EXISTS vendeurs_statut_boutique_check");

        Schema::table('vendeurs', function (Blueprint $table) {
            $table->dropColumn('statut_boutique');
        });
    }
};
