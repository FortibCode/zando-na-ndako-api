<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La formule d'abonnement du vendeur (gratuit / pro / vip) était entièrement écrite côté PHP —
 * $fillable et $casts du modèle Vendeur, accessors statut_abonnement / est_abonnement_actif /
 * badge_vendeur / limite_produits, endpoints d'abonnement de VendeurController — mais les trois
 * colonnes correspondantes n'ont jamais été créées en base.
 *
 * Conséquence : CatalogueController::vendeurs() classe les boutiques avec
 * orderByRaw("CASE WHEN formule_abonnement = 'vip' ..."), que PostgreSQL rejetait faute de colonne.
 * GET /api/vendeurs répondait donc 500 — or c'est l'appel qui alimente la liste des boutiques de
 * l'accueil client (catalogue "boutique-first"), qui restait vide sur mobile comme sur le web.
 * Souscrire un abonnement échouait pour la même raison, VendeurController écrivant les trois champs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeurs', function (Blueprint $table) {
            // 'gratuit' par défaut : les boutiques déjà en base conservent exactement le
            // comportement qu'elles avaient tant que l'accessor retombait sur cette valeur.
            $table->string('formule_abonnement', 20)->default('gratuit')->after('delai_moyen_preparation');

            // Laissé nullable : getStatutAbonnementAttribute() calcule 'gratuit' ou 'expire' à la
            // volée et ne retient la valeur stockée que pour un abonnement payant en cours.
            $table->string('statut_abonnement', 20)->nullable()->after('formule_abonnement');

            $table->timestamp('date_expiration_abonnement')->nullable()->after('statut_abonnement');
        });
    }

    public function down(): void
    {
        Schema::table('vendeurs', function (Blueprint $table) {
            $table->dropColumn(['formule_abonnement', 'statut_abonnement', 'date_expiration_abonnement']);
        });
    }
};
