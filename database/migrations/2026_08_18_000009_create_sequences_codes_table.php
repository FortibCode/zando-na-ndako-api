<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur pour les codes de référence lisibles (commandes, litiges, tickets...) au format
 * PREFIXE-AANNN (ex: ZN-26086 = 86ᵉ commande de 2026). Une ligne par (type, année) : le compteur
 * repart naturellement à 1 à chaque nouvelle année, et le passage d'une année à l'autre est donc
 * automatique — aucune tâche planifiée n'est nécessaire, il suffit que l'année courante change.
 * Voir App\Support\CodeSequenceGenerator pour la génération atomique (verrou de ligne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->timestamps();

            $table->unique(['type', 'annee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences_codes');
    }
};
