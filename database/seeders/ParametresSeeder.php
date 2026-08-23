<?php

namespace Database\Seeders;

use App\Models\ParametrePlateforme;
use Illuminate\Database\Seeder;

class ParametresSeeder extends Seeder
{
    public function run(): void
    {
        $params = [
            ['cle' => 'taux_commission_vendeur', 'valeur' => '10', 'description' => 'Pourcentage de commission prélevé sur les ventes'],
            ['cle' => 'frais_livraison_defaut', 'valeur' => '2000', 'description' => 'Frais de livraison par défaut en FCFA'],
            // Barème appliqué à la distance réelle de l'itinéraire (voir RoutingService::calculerFrais)
            ['cle' => 'frais_livraison_base', 'valeur' => '800', 'description' => "Frais de livraison fixes (FCFA), quelle que soit la distance parcourue"],
            ['cle' => 'frais_livraison_par_km', 'valeur' => '300', 'description' => 'Frais de livraison supplémentaires (FCFA) par kilomètre parcouru'],
            ['cle' => 'frais_livraison_plancher', 'valeur' => '1000', 'description' => 'Frais de livraison minimum (FCFA), même pour un trajet très court'],
            ['cle' => 'remuneration_livreur_fixe', 'valeur' => '2000', 'description' => 'Montant fixe versé au livreur par course'],
            ['cle' => 'nom_application', 'valeur' => 'Zando na Ndako', 'description' => 'Nom officiel de la plateforme'],
            ['cle' => 'contact_support_telephone', 'valeur' => '068745323', 'description' => 'Téléphone du support client'],

            // Attribution des commandes
            ['cle' => 'attribution_delai_alerte_minutes', 'valeur' => '20', 'description' => 'Délai (en minutes) après lequel une commande non attribuée est signalée en retard'],
            ['cle' => 'attribution_max_missions_simultanees', 'valeur' => '3', 'description' => 'Nombre maximum de livraisons actives simultanées par livreur avant signalement de surcharge'],
            ['cle' => 'attribution_rayon_max_km', 'valeur' => '15', 'description' => 'Distance maximale (km) recommandée entre un livreur et une commande à attribuer'],

            // Finances
            ['cle' => 'retrait_montant_minimum', 'valeur' => '1000', 'description' => 'Montant minimum (FCFA) autorisé pour une demande de retrait vendeur ou livreur'],

            // Notifications
            ['cle' => 'notifications_push_actives', 'valeur' => '1', 'description' => "Active ou coupe l'envoi effectif des notifications push (les notifications restent journalisées dans le centre in-app)"],

            // Sécurité
            ['cle' => 'otp_duree_validite_minutes', 'valeur' => '10', 'description' => 'Durée de validité (en minutes) des codes de vérification OTP envoyés par SMS'],
        ];

        foreach ($params as $p) {
            ParametrePlateforme::firstOrCreate(['cle' => $p['cle']], $p);
        }
    }
}