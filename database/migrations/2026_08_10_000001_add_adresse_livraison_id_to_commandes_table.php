<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute une référence optionnelle à l'adresse de livraison
     * sélectionnée par le client lors de la commande.
     */
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignUuid('adresse_livraison_id')
                ->nullable()
                ->after('client_id')
                ->constrained('adresses_livraison')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropForeign(['adresse_livraison_id']);
            $table->dropColumn('adresse_livraison_id');
        });
    }
};