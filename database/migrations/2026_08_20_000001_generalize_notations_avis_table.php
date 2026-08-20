<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La table notations_avis n'a jamais été branchée à aucune route ni contrôleur depuis sa création
 * (2026_07_24) — elle ne contient donc aucune ligne réelle. On peut la redessiner librement plutôt
 * que de bricoler autour de sa contrainte d'origine, qui ne permettait de noter que le vendeur ou le
 * livreur, toujours du point de vue d'un client (colonne client_id figée). On généralise le côté
 * « notateur » (client_id + type_notateur, symétrique à cible_id + type_cible) pour permettre aussi
 * au vendeur ou au livreur de noter le client, comme demandé.
 *
 * Un index unique (commande_id, type_notateur, type_cible) empêche qu'une même direction de
 * notation soit soumise deux fois pour la même commande (un client ne peut noter qu'une fois son
 * vendeur sur une commande donnée, etc.) — inutile de comparer aussi les id exacts puisqu'une
 * commande n'a qu'un seul client/vendeur/livreur assigné à la fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notations_avis', function (Blueprint $table) {
            $table->uuid('notateur_id')->nullable()->after('client_id');
            $table->string('type_notateur', 20)->nullable()->after('notateur_id');
        });

        DB::statement("UPDATE notations_avis SET notateur_id = client_id, type_notateur = 'client'");

        DB::statement('ALTER TABLE notations_avis DROP CONSTRAINT notations_avis_client_id_foreign');
        Schema::table('notations_avis', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });

        DB::statement('ALTER TABLE notations_avis ALTER COLUMN notateur_id SET NOT NULL');
        DB::statement('ALTER TABLE notations_avis ALTER COLUMN type_notateur SET NOT NULL');
        DB::statement("ALTER TABLE notations_avis ADD CONSTRAINT notations_avis_type_notateur_check CHECK (type_notateur IN ('client', 'vendeur', 'livreur'))");

        DB::statement('ALTER TABLE notations_avis DROP CONSTRAINT notations_avis_type_cible_check');
        DB::statement("ALTER TABLE notations_avis ADD CONSTRAINT notations_avis_type_cible_check CHECK (type_cible IN ('vendeur', 'livreur', 'client'))");

        Schema::table('notations_avis', function (Blueprint $table) {
            $table->unique(['commande_id', 'type_notateur', 'type_cible'], 'notations_avis_direction_unique');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('note_moyenne', 3, 2)->default(0)->after('coordonnees_gps');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('note_moyenne');
        });

        Schema::table('notations_avis', function (Blueprint $table) {
            $table->dropUnique('notations_avis_direction_unique');
        });

        DB::statement('ALTER TABLE notations_avis DROP CONSTRAINT notations_avis_type_cible_check');
        DB::statement("ALTER TABLE notations_avis ADD CONSTRAINT notations_avis_type_cible_check CHECK (type_cible IN ('vendeur', 'livreur'))");

        DB::statement('ALTER TABLE notations_avis DROP CONSTRAINT notations_avis_type_notateur_check');

        Schema::table('notations_avis', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->after('commande_id');
        });
        DB::statement("UPDATE notations_avis SET client_id = notateur_id WHERE type_notateur = 'client'");
        DB::statement('DELETE FROM notations_avis WHERE client_id IS NULL');

        Schema::table('notations_avis', function (Blueprint $table) {
            $table->uuid('client_id')->nullable(false)->change();
            $table->foreign('client_id')->references('id')->on('clients');
            $table->dropColumn(['notateur_id', 'type_notateur']);
        });
    }
};
