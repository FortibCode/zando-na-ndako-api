<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // `types_boutique_logos` (créée hier) ne stockait qu'un logo optionnel, la LISTE des types
        // elle-même restant la constante Vendeur::TYPES_BOUTIQUE (donc toujours un déploiement de
        // code pour ajouter/renommer/retirer un type). On la transforme en la véritable source de
        // vérité : chaque type de boutique existe maintenant comme une vraie ligne, gérable par un
        // admin (créer/modifier/voir/supprimer), avec son logo optionnel toujours porté ici.
        Schema::rename('types_boutique_logos', 'types_boutique');

        Schema::table('types_boutique', function (Blueprint $table) {
            $table->string('logo')->nullable()->change();
        });

        // Amorce la table avec les 7 valeurs jusqu'ici codées en dur, pour ne rien perdre des
        // boutiques déjà enregistrées avec l'une de ces catégories.
        $seed = [
            'Poissonnier & Produits de mer',
            'Boucher & Charcutier',
            'Maraîcher & Fruits / Légumes',
            'Épicier & Produits alimentaires',
            'Artisanat & Fait maison',
            'Mode & Habillement',
            'Autre commerce',
        ];
        foreach ($seed as $type) {
            DB::table('types_boutique')->updateOrInsert(
                ['type' => $type],
                ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('types_boutique', function (Blueprint $table) {
            $table->string('logo')->nullable(false)->change();
        });
        Schema::rename('types_boutique', 'types_boutique_logos');
    }
};
