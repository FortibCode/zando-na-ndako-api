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
        Schema::create('bannieres_publicitaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendeur_id')->nullable()->constrained('vendeurs')->nullOnDelete();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->string('image_url');
            $table->enum('type_cible', ['boutique', 'produit', 'categorie', 'externe'])->default('boutique');
            $table->string('cible_id')->nullable(); // ID boutique / ID produit / slug catégorie
            $table->timestamp('date_debut')->useCurrent();
            $table->timestamp('date_fin')->nullable();
            $table->enum('statut', ['en_attente', 'actif', 'expire', 'rejete'])->default('actif');
            $table->integer('priorite')->default(0);
            $table->unsignedBigInteger('nombre_vues')->default(0);
            $table->unsignedBigInteger('nombre_clics')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bannieres_publicitaires');
    }
};
