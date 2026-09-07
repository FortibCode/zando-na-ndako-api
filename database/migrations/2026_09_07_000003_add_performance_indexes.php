<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->index(['statut_disponibilite', 'nom_produit'], 'idx_produits_dispo_nom');
            $table->index(['categorie_id', 'statut_disponibilite'], 'idx_produits_cat_dispo');
            $table->index(['vendeur_id', 'statut_disponibilite'], 'idx_produits_vendeur_dispo');
        });

        Schema::table('vendeurs', function (Blueprint $table) {
            $table->index(['statut_validation', 'statut_boutique'], 'idx_vendeurs_validation_boutique');
            $table->index(['categorie_principale'], 'idx_vendeurs_cat_principale');
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->index(['client_id', 'statut_commande'], 'idx_commandes_client_statut');
            $table->index(['vendeur_id', 'statut_commande'], 'idx_commandes_vendeur_statut');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropIndex('idx_produits_dispo_nom');
            $table->dropIndex('idx_produits_cat_dispo');
            $table->dropIndex('idx_produits_vendeur_dispo');
        });

        Schema::table('vendeurs', function (Blueprint $table) {
            $table->dropIndex('idx_vendeurs_validation_boutique');
            $table->dropIndex('idx_vendeurs_cat_principale');
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropIndex('idx_commandes_client_statut');
            $table->dropIndex('idx_commandes_vendeur_statut');
        });
    }
};
