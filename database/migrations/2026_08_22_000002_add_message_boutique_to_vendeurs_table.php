<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'écran mobile "Statut de la boutique" laisse le vendeur saisir un "Message aux clients"
 * (ex: "Merci pour votre compréhension 🙏") à côté du statut ouverte/pause/fermée, mais aucune
 * colonne n'existait pour le stocker : le message était gardé uniquement dans l'état React local
 * du contexte vendeur mobile et disparaissait au redémarrage de l'application, alors que l'écran
 * affichait une confirmation "Enregistré" laissant croire à une vraie sauvegarde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeurs', function (Blueprint $table) {
            $table->string('message_boutique', 300)->nullable()->after('statut_boutique');
        });
    }

    public function down(): void
    {
        Schema::table('vendeurs', function (Blueprint $table) {
            $table->dropColumn('message_boutique');
        });
    }
};
