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
        Schema::table('vendeurs', function (Blueprint $table) {
            // Position réelle de la boutique (point de collecte), nécessaire pour calculer
            // le prix de livraison sur la distance réelle jusqu'au client.
            $table->json('coordonnees_gps')->nullable()->after('zone_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendeurs', function (Blueprint $table) {
            $table->dropColumn('coordonnees_gps');
        });
    }
};
