<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le statut "en_route_client" (départ vers le client)
     * et la colonne date_depart_client sur la table livraisons.
     *
     * Sur PostgreSQL, Laravel crée les colonnes enum() comme VARCHAR + CHECK constraint.
     * Pour étendre les valeurs, il faut supprimer l'ancienne contrainte et en créer une nouvelle.
     */
    public function up(): void
    {
        // --- Table livraisons : étendre statut_livraison ---
        DB::statement("ALTER TABLE livraisons DROP CONSTRAINT IF EXISTS livraisons_statut_livraison_check");
        DB::statement("ALTER TABLE livraisons ADD CONSTRAINT livraisons_statut_livraison_check CHECK (statut_livraison IN ('assigne', 'en_cours', 'en_route_client', 'livree', 'echouee'))");

        // --- Table commandes : étendre statut_commande ---
        DB::statement("ALTER TABLE commandes DROP CONSTRAINT IF EXISTS commandes_statut_commande_check");
        DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_statut_commande_check CHECK (statut_commande IN ('confirmee', 'achat_marche', 'preparation', 'en_route', 'en_route_client', 'livree', 'annulee'))");

        // --- Ajouter la colonne date_depart_client ---
        Schema::table('livraisons', function (Blueprint $table) {
            $table->timestamp('date_depart_client')->nullable()->after('date_collecte');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer la colonne date_depart_client
        Schema::table('livraisons', function (Blueprint $table) {
            $table->dropColumn('date_depart_client');
        });

        // Restaurer statut_livraison sans 'en_route_client'
        DB::statement("ALTER TABLE livraisons DROP CONSTRAINT IF EXISTS livraisons_statut_livraison_check");
        DB::statement("ALTER TABLE livraisons ADD CONSTRAINT livraisons_statut_livraison_check CHECK (statut_livraison IN ('assigne', 'en_cours', 'livree', 'echouee'))");

        // Restaurer statut_commande sans 'en_route_client'
        DB::statement("ALTER TABLE commandes DROP CONSTRAINT IF EXISTS commandes_statut_commande_check");
        DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_statut_commande_check CHECK (statut_commande IN ('confirmee', 'achat_marche', 'preparation', 'en_route', 'livree', 'annulee'))");
    }
};