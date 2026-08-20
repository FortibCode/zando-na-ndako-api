<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historique structuré des décisions administratives sur un litige — distinct du simple champ
 * texte litiges.decision (conservé pour l'affichage résumé côté liste, synchronisé automatiquement
 * avec la décision la plus récente). Chaque ligne ici est un acte formel et daté, avec un type
 * exploitable par du code (déclenche un remboursement, un remplacement...) plutôt qu'un texte libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('litige_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('litige_id')->constrained('litiges')->cascadeOnDelete();
            $table->foreignUuid('admin_id')->constrained('administrateurs');
            $table->string('decision_type');
            $table->text('reason');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('litige_id');
        });

        DB::statement("ALTER TABLE litige_decisions ADD CONSTRAINT litige_decisions_decision_type_check CHECK (decision_type IN ('acceptee', 'rejetee', 'remboursement_total', 'remboursement_partiel', 'remplacement_produit', 'retour_produit', 'dedommagement_vendeur', 'dedommagement_client', 'aucune_action'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('litige_decisions');
    }
};
