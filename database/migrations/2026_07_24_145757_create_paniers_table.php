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
        Schema::create('paniers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->onDelete('cascade');
            $table->enum('statut', ['actif', 'valide', 'abandonne'])->default('actif');
            $table->timestamp('date_creation')->useCurrent();
            $table->timestamp('date_maj')->nullable();
            $table->foreignUuid('beneficiaire_id')->nullable()->constrained('beneficiaires');
            $table->string('lien_partage_panier')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paniers');
    }
};
