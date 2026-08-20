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
        Schema::create('vendeurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('nom_commerce');
            $table->string('categorie_principale'); // poissonnier, boucher, maraicher, epicier
            $table->foreignUuid('zone_id')->nullable()->constrained('zones_livraison');
            $table->decimal('note_moyenne', 3, 2)->default(0);
            $table->enum('statut_validation', ['en_attente', 'valide', 'suspendu'])->default('en_attente');
            $table->decimal('solde_disponible', 15, 2)->default(0);
            $table->string('photo_boutique')->nullable();
            $table->string('document_identite')->nullable();
            $table->string('registre_commerce')->nullable();
            $table->string('numero_mobile_money_reception')->nullable();
            $table->string('horaires_ouverture')->nullable();
            $table->integer('delai_moyen_preparation')->default(15); // en minutes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendeurs');
    }
};
