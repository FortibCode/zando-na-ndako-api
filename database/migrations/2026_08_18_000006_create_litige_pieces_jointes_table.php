<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preuves jointes à un litige (photos, documents) — liées au litige, et optionnellement à un
 * message précis du fil (message_id nullable : une pièce peut aussi être ajoutée seule, sans
 * texte d'accompagnement, via le bouton "Ajouter une preuve"). Suit la même convention de stockage
 * que le reste de l'app (disque 'public', dossier photos/<feature>) — voir
 * VendeurController::ajouterProduit() pour le précédent exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('litige_pieces_jointes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('litige_id')->constrained('litiges')->cascadeOnDelete();
            $table->foreignUuid('message_id')->nullable()->constrained('litige_messages')->nullOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('litige_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('litige_pieces_jointes');
    }
};
