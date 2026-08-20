<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vendeur;
use App\Models\ZoneLivraison;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class VendeursSeeder extends Seeder
{
    public function run(): void
    {
        $zone = ZoneLivraison::first();

        $user = User::firstOrCreate(
            ['email' => 'vendeur@zando.cg'],
            [
                'nom'               => 'Mambenga',
                'prenom'            => 'Jean',
                'telephone'         => '066454321',
                'mot_de_passe_hash' => Hash::make('password123'),
                'type_utilisateur'  => 'vendeur',
                'statut_compte'     => 'actif',
                'consentement_cgu'  => true,
            ]
        );

        Vendeur::firstOrCreate(
            ['user_id' => $user->id],
            [
                'nom_commerce'                 => 'Zando Grand Marché',
                'categorie_principale'         => 'Alimentation Générale',
                'zone_id'                      => $zone?->id,
                'statut_validation'            => 'valide',
                'solde_disponible'             => 150000.00,
                'numero_mobile_money_reception'=> '065463234',
            ]
        );
    }
}