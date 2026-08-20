<?php

namespace Database\Seeders;

use App\Models\ZoneLivraison;
use Illuminate\Database\Seeder;

class ZonesLivraisonSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            [
                'nom_zone'             => 'Brazzaville Centre',
                'ville'                => 'Brazzaville',
                'quartiers_couverts'   => ['Moungalie', 'Centre-ville', 'Plateau', 'Poto-Poto'],
                'frais_livraison_base' => 2000,
                'delai_estime_min'     => 30,
                'delai_estime_max'     => 60,
                'statut_actif'         => true,
            ],
            [
                'nom_zone'             => 'Brazzaville Nord',
                'ville'                => 'Brazzaville',
                'quartiers_couverts'   => ['Ouenzé', 'Talangaï', 'Ngamaba', 'Mikala'],
                'frais_livraison_base' => 2500,
                'delai_estime_min'     => 35,
                'delai_estime_max'     => 70,
                'statut_actif'         => true,
            ],
            [
                'nom_zone'             => 'Brazzaville Sud',
                'ville'                => 'Brazzaville',
                'quartiers_couverts'   => ['Bacongo', 'Makélékélé', 'Kinsoundi', 'Mfilou'],
                'frais_livraison_base' => 2500,
                'delai_estime_min'     => 40,
                'delai_estime_max'     => 75,
                'statut_actif'         => true,
            ],
            [
                'nom_zone'             => 'Brazzaville Est',
                'ville'                => 'Brazzaville',
                'quartiers_couverts'   => ['Djiri', 'Mfilou', 'Nganga-Lingolo', 'Massengo'],
                'frais_livraison_base' => 3000,
                'delai_estime_min'     => 45,
                'delai_estime_max'     => 90,
                'statut_actif'         => true,
            ],
        ];

        foreach ($zones as $zone) {
            ZoneLivraison::firstOrCreate(['nom_zone' => $zone['nom_zone']], $zone);
        }
    }
}