<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table des adresses de livraison des clients.
     * Chaque client peut enregistrer plusieurs adresses
     * (Maison, Bureau, Autre…) et en définir une par défaut.
     */
    public function up(): void
    {
        Schema::create('adresses_livraison', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->onDelete('cascade');

            // Libellé court (Maison, Bureau, Autre…)
            $table->string('label')->default('Maison');

            // Destinataire
            $table->string('nom_complet')->nullable();
            $table->string('telephone')->nullable();

            // Localisation
            $table->string('ville')->default('Brazzaville');
            $table->string('quartier')->nullable();
            $table->text('adresse');
            $table->json('coordonnees_gps')->nullable();
            $table->text('instructions')->nullable();

            $table->boolean('est_defaut')->default(false);
            $table->timestamps();

            // Index pour une recherche rapide par client
            $table->index(['client_id', 'est_defaut']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adresses_livraison');
    }
};