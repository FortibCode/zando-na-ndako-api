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
        Schema::table('commandes', function (Blueprint $table) {
            // Distance routière réelle (vendeur → livraison) utilisée pour calculer frais_livraison.
            // Reste nulle quand le calcul par itinéraire n'a pas été possible (pas de coordonnées
            // vendeur, ou service de routage indisponible) — la commande retombe alors sur le tarif
            // de zone habituel.
            $table->decimal('distance_estimee_km', 8, 2)->nullable()->after('frais_livraison');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('distance_estimee_km');
        });
    }
};
