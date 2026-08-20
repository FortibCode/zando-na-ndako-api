<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messagerie liée à une commande précise : partagée entre le client, le vendeur et le
     * livreur qui y participent (un seul fil par commande, chaque message identifie son
     * expéditeur via expediteur_user_id).
     */
    public function up(): void
    {
        Schema::create('messages_commande', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignUuid('expediteur_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('contenu');
            $table->boolean('lu')->default(false);
            $table->timestamps();

            $table->index(['commande_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_commande');
    }
};
