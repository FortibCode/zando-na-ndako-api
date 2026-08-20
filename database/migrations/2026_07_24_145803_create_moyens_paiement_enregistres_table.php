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
         Schema::create('moyens_paiement_enregistres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('type_moyen', ['airtel_money', 'mtn_momo', 'carte_bancaire', 'paypal', 'stripe']);
            $table->string('token_paiement')->nullable();
            $table->string('numero_masque')->nullable();
            $table->boolean('est_par_defaut')->default(false);
            $table->string('devise');
            $table->enum('statut', ['actif', 'expire'])->default('actif');
            $table->string('nom_titulaire')->nullable();
            $table->string('date_expiration_carte')->nullable();
            $table->string('pays_emission')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moyens_paiement_enregistres');
    }
};
