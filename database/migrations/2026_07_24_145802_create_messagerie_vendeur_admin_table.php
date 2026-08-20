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
        Schema::create('messagerie_vendeur_admin', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendeur_id')->constrained('vendeurs');
            $table->foreignUuid('admin_id')->constrained('administrateurs');
            $table->text('contenu');
            $table->timestamp('date_envoi')->useCurrent();
            $table->boolean('statut_lecture')->default(false);
            $table->string('id_conversation'); // Pour regrouper les messages
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messagerie_vendeur_admin');
    }
};
