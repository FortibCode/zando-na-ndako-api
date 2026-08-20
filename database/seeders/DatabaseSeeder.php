<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Administrateur;
use App\Models\RoleUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Créer l'administrateur système
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@zando.cg'],
            [
                'nom'               => 'Admin',
                'prenom'            => 'Super',
                'telephone'         => '080000000',
                'mot_de_passe_hash' => Hash::make('admin123456'),
                'type_utilisateur'  => 'administrateur',
                'statut_compte'     => 'actif',
                'consentement_cgu'  => true,
            ]
        );

        Administrateur::firstOrCreate(
            ['user_id' => $adminUser->id],
            [
                'role_admin'      => 'super_admin',
                'permissions'     => ['all'],
                'date_nomination' => now(),
            ]
        );

        // CheckRole (routes 'role:super_admin', ex. gestion des rôles / logs d'audit) ne regarde
        // que type_utilisateur et role_user — pas Administrateur.role_admin — donc sans cette ligne
        // le compte super_admin se voit refuser l'accès à ses propres routes super_admin.
        RoleUser::firstOrCreate(['user_id' => $adminUser->id, 'role' => 'super_admin']);

        // Appeler tous les autres seeders
        $this->call([
            ZonesLivraisonSeeder::class,
            CategoriesSeeder::class,
            TauxChangeSeeder::class,
            ParametresSeeder::class,
            PermissionSeeder::class,
            VendeursSeeder::class,
            LivreursSeeder::class,
            ProduitsSeeder::class,
            ClientsSeeder::class,
        ]);
    }
}