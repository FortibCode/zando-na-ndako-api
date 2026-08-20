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
        Schema::create('livraisons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes');
            $table->foreignUuid('livreur_id')->constrained('livreurs');
            $table->enum('statut_livraison', ['assigne', 'en_cours', 'livree', 'echouee'])->default('assigne');
            $table->timestamp('date_collecte')->nullable();
            $table->timestamp('date_livraison')->nullable();
            $table->string('photo_preuve')->nullable();
            $table->decimal('distance_parcourue', 10, 2)->nullable();
            $table->integer('duree_reelle')->nullable(); // en minutes
            $table->string('motif_incident')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('livraisons');
    }
};
