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
        Schema::create('produits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendeur_id')->constrained('vendeurs')->onDelete('cascade');
            $table->foreignUuid('categorie_id')->constrained('categories');
            $table->string('nom_produit');
            $table->text('description')->nullable();
            $table->decimal('prix_unitaire', 15, 2);
            $table->string('unite_mesure')->default('kg'); // kg, piece, litre
            $table->integer('quantite_stock')->default(0);
            $table->enum('statut_disponibilite', ['disponible', 'rupture'])->default('disponible');
            $table->string('photo_produit')->nullable();
            $table->enum('type_fraicheur', ['frais', 'fume', 'congele'])->nullable();
            $table->timestamp('date_maj_prix')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
