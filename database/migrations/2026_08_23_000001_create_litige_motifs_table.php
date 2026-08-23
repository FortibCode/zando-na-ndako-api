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
        // Remplace LitigeController::MOTIFS (constante PHP) — la même liste de 9 codes était
        // indépendamment recopiée côté mobile (services/api.ts + client/disputes/new.tsx) et web
        // (lib/api.ts LITIGE_MOTIFS), sans qu'aucune des deux app ne les tienne réellement de ce
        // backend. `code` est la valeur stockée sur litiges.motif (varchar libre, inchangé) ;
        // `libelle` sert de repli pour tout client qui n'a pas de traduction spécifique pour un
        // code ajouté depuis l'admin après coup.
        Schema::create('litige_motifs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('libelle');
            $table->timestamps();
        });

        $seed = [
            ['code' => 'produit_non_recu', 'libelle' => 'Produit non reçu'],
            ['code' => 'produit_incorrect', 'libelle' => 'Produit incorrect'],
            ['code' => 'produit_endommage', 'libelle' => 'Produit endommagé'],
            ['code' => 'produit_non_conforme', 'libelle' => 'Produit non conforme'],
            ['code' => 'article_manquant', 'libelle' => 'Article manquant'],
            ['code' => 'probleme_livraison', 'libelle' => 'Problème de livraison'],
            ['code' => 'probleme_paiement', 'libelle' => 'Problème de paiement'],
            ['code' => 'probleme_remboursement', 'libelle' => 'Problème de remboursement'],
            ['code' => 'autre', 'libelle' => 'Autre'],
        ];
        foreach ($seed as $row) {
            DB::table('litige_motifs')->updateOrInsert(
                ['code' => $row['code']],
                ['id' => (string) Str::uuid(), 'libelle' => $row['libelle'], 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('litige_motifs');
    }
};
