<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étend le workflow de résolution des litiges : le statut ne se limitait qu'à
 * ouvert/en_cours/resolu/rejete (traitement en un seul geste par l'admin) — on ajoute les états
 * intermédiaires nécessaires à un vrai arbitrage (attente d'une réponse du client ou du vendeur,
 * escalade, annulation) ainsi qu'un numéro lisible (LIT-XXXXXXXX, même convention que
 * tickets_support.numero) pour l'affichage.
 *
 * Sur PostgreSQL, Laravel crée les colonnes enum() comme VARCHAR + CHECK constraint : pour étendre
 * les valeurs, il faut supprimer l'ancienne contrainte et en recréer une nouvelle (déjà fait pour
 * livraisons/commandes dans 2026_07_28_000001_align_livreur_availability_status.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('litiges', function (Blueprint $table) {
            $table->string('numero')->nullable()->unique()->after('id');
        });

        DB::statement("ALTER TABLE litiges DROP CONSTRAINT IF EXISTS litiges_statut_check");
        DB::statement("ALTER TABLE litiges ADD CONSTRAINT litiges_statut_check CHECK (statut IN ('ouvert', 'attente_vendeur', 'attente_client', 'en_cours', 'escalade', 'resolu', 'rejete', 'annule'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE litiges DROP CONSTRAINT IF EXISTS litiges_statut_check");
        DB::statement("ALTER TABLE litiges ADD CONSTRAINT litiges_statut_check CHECK (statut IN ('ouvert', 'en_cours', 'resolu', 'rejete'))");

        Schema::table('litiges', function (Blueprint $table) {
            $table->dropColumn('numero');
        });
    }
};
