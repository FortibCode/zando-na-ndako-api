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
        Schema::create('notations_avis', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes');
            $table->foreignUuid('client_id')->constrained('clients');
            $table->uuid('cible_id'); // ID du vendeur ou du livreur
            $table->enum('type_cible', ['vendeur', 'livreur']);
            $table->integer('note')->between(1, 5);
            $table->text('commentaire')->nullable();
            $table->timestamp('date_notation')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notations_avis');
    }
};
