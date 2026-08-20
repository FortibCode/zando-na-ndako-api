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
            
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary(); // Utilisation d'UUID pour plus de sécurité
            $table->string('nom');
            $table->string('prenom');
            $table->string('email')->unique();
            $table->string('telephone')->unique();
            $table->string('mot_de_passe_hash');
            $table->enum('type_utilisateur', ['client', 'vendeur', 'livreur', 'administrateur']);
            $table->string('langue_preferee')->default('francais');
            $table->enum('statut_compte', ['actif', 'suspendu', 'en_attente_validation'])->default('en_attente_validation');
            $table->string('photo_profil')->nullable();
            $table->timestamp('date_inscription')->useCurrent();
            $table->timestamp('derniere_connexion')->nullable();
            $table->string('google_id')->nullable();
            $table->string('devise_preferee')->default('FCFA');
            $table->string('pays_residence')->nullable();
            $table->boolean('consentement_cgu')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
