<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le fil de discussion (réponses) à la messagerie vendeur ↔ admin, et répare au
     * passage les colonnes `objet` / `expediteur` déjà utilisées par VendeurController mais
     * jamais créées par la migration d'origine (envoyerMessage() plantait donc systématiquement
     * en base : `expediteur`/`objet` n'existaient pas et `admin_id`/`id_conversation` étaient
     * NOT NULL sans être fournis).
     */
    public function up(): void
    {
        Schema::table('messagerie_vendeur_admin', function (Blueprint $table) {
            $table->string('objet')->nullable()->after('admin_id');
            $table->string('expediteur', 20)->default('vendeur')->after('objet');
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('messagerie_vendeur_admin')
                ->nullOnDelete();
        });

        // admin_id : pas encore d'admin assigné quand un vendeur ouvre une nouvelle conversation.
        // id_conversation : champ legacy jamais renseigné par le code actuel, on le libère plutôt
        // que de le supprimer pour ne pas perdre de données existantes.
        DB::statement('ALTER TABLE messagerie_vendeur_admin ALTER COLUMN admin_id DROP NOT NULL');
        DB::statement('ALTER TABLE messagerie_vendeur_admin ALTER COLUMN id_conversation DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messagerie_vendeur_admin', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'objet', 'expediteur']);
        });

        DB::statement('ALTER TABLE messagerie_vendeur_admin ALTER COLUMN admin_id SET NOT NULL');
        DB::statement('ALTER TABLE messagerie_vendeur_admin ALTER COLUMN id_conversation SET NOT NULL');
    }
};
