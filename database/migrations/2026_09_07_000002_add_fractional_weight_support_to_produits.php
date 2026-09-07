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
            if (!Schema::hasColumn('produits', 'pas_quantite')) {
                $table->decimal('pas_quantite', 8, 2)->default(1.00)->after('unite_mesure');
            }
            if (!Schema::hasColumn('produits', 'quantite_minimale')) {
                $table->decimal('quantite_minimale', 8, 2)->default(1.00)->after('pas_quantite');
            }
        });

        Schema::table('lignes_panier', function (Blueprint $table) {
            $table->decimal('quantite', 10, 3)->change();
        });

        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->decimal('quantite', 10, 3)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['pas_quantite', 'quantite_minimale']);
        });

        Schema::table('lignes_panier', function (Blueprint $table) {
            $table->integer('quantite')->change();
        });

        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->integer('quantite')->change();
        });
    }
};
