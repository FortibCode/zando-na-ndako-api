<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_support', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero')->unique();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->enum('categorie', ['commande', 'paiement', 'livraison', 'vendeur', 'livreur', 'produit', 'remboursement', 'compte', 'autre']);
            $table->string('sujet');
            $table->text('description');
            $table->enum('priorite', ['basse', 'normale', 'haute', 'urgente'])->default('normale');
            $table->foreignUuid('admin_assigne_id')->nullable()->constrained('administrateurs')->nullOnDelete();
            $table->enum('statut', ['ouvert', 'en_cours', 'en_attente', 'resolu', 'ferme'])->default('ouvert');
            $table->timestamp('date_derniere_reponse')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_reponses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained('tickets_support')->cascadeOnDelete();
            $table->foreignUuid('auteur_id')->constrained('users');
            $table->text('message');
            $table->boolean('est_note_interne')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reponses');
        Schema::dropIfExists('tickets_support');
    }
};
