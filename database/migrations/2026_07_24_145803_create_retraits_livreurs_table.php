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
        Schema::create('retraits_livreurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('livreur_id')->constrained('livreurs');
            $table->decimal('montant', 10, 2);
            $table->enum('moyen_retrait', ['mobile_money', 'virement']);
            $table->enum('statut', ['en_attente', 'valide', 'rejete'])->default('en_attente');
            $table->timestamp('date_demande')->useCurrent();
            $table->timestamp('date_traitement')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retraits_livreurs');
    }
};
