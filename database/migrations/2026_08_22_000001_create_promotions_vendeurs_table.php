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
        // Promotions créées par le vendeur lui-même (à ne pas confondre avec `promotions` /
        // `promotion_produits` qui restent la bannière marketing gérée par l'administrateur —
        // voir AdminController::promotions()). Ici : self-service, scoping strict au vendeur
        // authentifié, sur toute la boutique (produit_id NULL) ou un produit précis.
        Schema::create('promotions_vendeurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendeur_id')->constrained('vendeurs')->onDelete('cascade');
            $table->foreignUuid('produit_id')->nullable()->constrained('produits')->onDelete('cascade');
            $table->string('titre');
            $table->text('description')->nullable();
            $table->enum('type_reduction', ['pourcentage', 'montant_fixe'])->default('pourcentage');
            $table->decimal('valeur_reduction', 10, 2);
            $table->timestamp('date_debut');
            $table->timestamp('date_fin')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions_vendeurs');
    }
};
