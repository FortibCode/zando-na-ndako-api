<?php

namespace Database\Seeders;

use App\Models\Livreur;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LivreursSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'okombifortune97@gmail.com'],
            [
                'nom'               => 'Okombi',
                'prenom'            => 'Jean-Paul',
                'telephone'         => '067746860',
                'mot_de_passe_hash' => Hash::make('password123'),
                'type_utilisateur'  => 'livreur',
                'statut_compte'     => 'actif',
                'consentement_cgu'  => true,
            ]
        );

        Livreur::firstOrCreate(
            ['user_id' => $user->id],
            [
                'type_vehicule'        => 'moto',
                'immatriculation_vehicule' => 'TEST-001',
                'statut_validation'    => 'valide',
                'statut_disponibilite' => 'disponible',
                'solde_disponible'     => 0,
                'note_moyenne'         => 5,
            ]
        );
    }
}