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
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->enum('type_reduction', ['pourcentage', 'montant_fixe']);
            $table->decimal('valeur_reduction', 10, 2);
            $table->timestamp('date_debut');
            $table->timestamp('date_fin');
            $table->boolean('statut_actif')->default(true);
            $table->string('image_bandeau')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
