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
       Schema::create('lignes_panier', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('panier_id')->constrained('paniers')->onDelete('cascade');
            $table->foreignUuid('produit_id')->constrained('produits');
            $table->integer('quantite');
            $table->decimal('prix_unitaire_moment', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_panier');
    }
};
