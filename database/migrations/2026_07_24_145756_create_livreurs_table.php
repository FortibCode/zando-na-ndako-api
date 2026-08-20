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
       Schema::create('livreurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('type_vehicule', ['moto', 'voiture']);
            $table->string('immatriculation_vehicule');
            $table->enum('statut_disponibilite', ['disponible', 'indisponible'])->default('indisponible');
            $table->enum('statut_validation', ['en_attente', 'valide', 'suspendu'])->default('en_attente');
            $table->decimal('note_moyenne', 3, 2)->default(0);
            $table->decimal('solde_disponible', 15, 2)->default(0);
            $table->string('document_identite')->nullable();
            $table->string('permis_conduire')->nullable();
            $table->json('zone_intervention')->nullable();
            $table->string('numero_mobile_money_reception')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('livreurs');
    }
};
