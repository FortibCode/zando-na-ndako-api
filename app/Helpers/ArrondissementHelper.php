<?php

namespace App\Helpers;

class ArrondissementHelper
{
    public static function getLocations(): array
    {
        return [
            'Brazzaville' => [
                'Arrondissement 1 - Makélékélé' => [
                    'Marché Bourreau', 'Kinsoundi', 'Diata', 'Château d\'eau', 'Mafouta', 'Mayanga', 'Moussosso', 'Rond-Point Makélékélé'
                ],
                'Arrondissement 2 - Bacongo' => [
                    'Marché Total', 'Mpissa', 'Case Barnier', 'Dahomey', 'La Glacière', 'Lycée Savorgnan', 'Centre Culturel Français'
                ],
                'Arrondissement 3 - Poto-Poto' => [
                    'Marché Poto-Poto', 'Sainte-Anne', 'Fond Tié-Tié', 'Rue Congo', 'Rue Bangui', 'Maison Commune', 'Rond-Point Moungali'
                ],
                'Arrondissement 4 - Moungali' => [
                    'Marché Dix Francs', 'Moukondo', 'CEG Moungali', 'Palais des Écritures', 'Avenue de la Paix', 'Lycée Chaminade'
                ],
                'Arrondissement 5 - Ouenzé' => [
                    'Marché Ouenzé', 'Texaco', '3 Francs', 'Place de la Paix', 'Kimbangu', 'Plateau des 15 Ans', 'Marché Mikalou'
                ],
                'Arrondissement 6 - Talangaï' => [
                    'Marché Intendance', 'Petit Chose', 'Mikalou', 'Massina', 'Mpila', 'CEG Liberté', 'Rond-Point Kintélé'
                ],
                'Arrondissement 7 - Mfilou' => [
                    'Ngamaba', 'Moungali III', 'PK45', 'La Poudrière', 'Albert Bayonne', 'Marché Mfilou', 'La Sangha'
                ],
                'Arrondissement 8 - Madibou' => [
                    'Sangolo', 'Kombé', 'Matsoua', 'Loua', 'Kibeliba', 'Péage Madibou'
                ],
                'Arrondissement 9 - Djiri' => [
                    'Massengo', 'Nkombo', 'Manianga', 'Mikala', 'Élan', 'Université Denis Sassou Nguesso', 'Kintélé'
                ],
            ],
            'Pointe-Noire' => [
                'Arrondissement 1 - Lumumba' => [
                    'Grand Marché', 'Port Autonome', 'Centre-ville', 'Lycée Victor Augagneur', 'KM4', 'Côte Sauvage'
                ],
                'Arrondissement 2 - Mvou-Mvou' => [
                    'Marché Mvou-Mvou', 'Cité du Port', 'Chic', 'Rex', 'Fond Tié-Tié', 'KM8'
                ],
                'Arrondissement 3 - Tié-Tié' => [
                    'Fond Tié-Tié', 'Marché Tié-Tié', 'Poudrière', 'KM4', 'Lycée Karl Marx'
                ],
                'Arrondissement 4 - Loandjili' => [
                    'Songolo', 'Vindoulou', 'Mpaka', 'Mongo-Kamba', 'Marché Loandjili'
                ],
                'Arrondissement 5 - Mongo-Poumpou' => [
                    'Nkouikou', 'Tchimba', 'Matendé', 'Base Agip'
                ],
                'Arrondissement 6 - Ngoyo' => [
                    'Tchimbamba', 'Mpita', 'Pointe-Indienne', 'Zone Industrielle Ngoyo'
                ],
            ],
        ];
    }
}
