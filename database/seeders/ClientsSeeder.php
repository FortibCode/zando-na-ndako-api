<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Client;
use App\Models\Beneficiaire;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientsSeeder extends Seeder
{
    public function run(): void
    {
        // Client Local
        $userLocal = User::firstOrCreate(
            ['email' => 'client@zando.cg'],
            [
                'nom'               => 'Kabila',
                'prenom'            => 'Marie',
                'telephone'         => '066543210',
                'mot_de_passe_hash' => Hash::make('password123'),
                'type_utilisateur'  => 'client',
                'statut_compte'     => 'actif',
                'consentement_cgu'  => true,
            ]
        );

        $clientLocal = Client::firstOrCreate(
            ['user_id' => $userLocal->id],
            ['adresse_principale' => 'Avenue De la paix 12, Moungalie, Brazzaville', 'est_diaspora' => false]
        );

        // Client Diaspora
        $userDiaspora = User::firstOrCreate(
            ['email' => 'diaspora@zando.cg'],
            [
                'nom'               => 'Ndombele',
                'prenom'            => 'Patrick',
                'telephone'         => '+33612345678',
                'mot_de_passe_hash' => Hash::make('password123'),
                'type_utilisateur'  => 'client',
                'statut_compte'     => 'actif',
                'consentement_cgu'  => true,
                'devise_preferee'   => 'EUR',
                'pays_residence'    => 'France',
            ]
        );

        $clientDiaspora = Client::firstOrCreate(
            ['user_id' => $userDiaspora->id],
            ['adresse_principale' => 'Paris, France', 'est_diaspora' => true]
        );

        // Bénéficiaire pour la diaspora
        Beneficiaire::firstOrCreate(
            ['client_id' => $clientDiaspora->id, 'telephone' => '067746860'],
            [
                'nom'        => 'Maman Ndombele',
                'adresse'    => 'Avenue mayombe 34',
                'quartier'   => 'Mokondzi Nguaka',
                'relation'   => 'Mère',
                'est_defaut' => true,
                'date_ajout' => now(),
            ]
        );
    }
}