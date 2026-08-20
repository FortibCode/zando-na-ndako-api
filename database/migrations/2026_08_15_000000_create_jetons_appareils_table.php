<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table des jetons Expo Push par appareil (client, vendeur ou livreur).
     * Un utilisateur peut avoir plusieurs appareils ; un même jeton (identifiant unique
     * d'une installation d'app sur un appareil) n'appartient qu'à un seul utilisateur à la
     * fois — il est ré-attribué au compte actif en cas de changement de session sur le même
     * appareil (voir UserController::enregistrerPushToken).
     */
    public function up(): void
    {
        Schema::create('jetons_appareils', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('token')->unique();
            $table->string('plateforme')->default('inconnue'); // ios | android | web
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jetons_appareils');
    }
};
