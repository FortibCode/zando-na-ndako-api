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
        Schema::create('zones_livraison', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom_zone');
            $table->string('ville')->default('Brazzaville');
            $table->json('quartiers_couverts');
            $table->decimal('frais_livraison_base', 10, 2);
            $table->integer('delai_estime_min')->default(30); // en minutes
            $table->integer('delai_estime_max')->default(60);
            $table->boolean('statut_actif')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones_livraison');
    }
};
