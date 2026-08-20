<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation d'un litige — même principe que messages_commande (un seul fil partagé), mais
 * sender_type distingue explicitement client / vendeur / admin / system, ce qui permet à
 * l'interface admin d'afficher qui parle sans avoir à recroiser avec la commande à chaque fois.
 * est_note_interne (même flag que ticket_reponses) sépare les échanges visibles par les parties
 * des notes internes que l'admin peut laisser pour lui-même / les autres admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('litige_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('litige_id')->constrained('litiges')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_type');
            $table->text('message');
            $table->boolean('est_note_interne')->default(false);
            $table->timestamps();

            $table->index(['litige_id', 'created_at']);
        });

        DB::statement("ALTER TABLE litige_messages ADD CONSTRAINT litige_messages_sender_type_check CHECK (sender_type IN ('client', 'vendeur', 'admin', 'system'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('litige_messages');
    }
};
