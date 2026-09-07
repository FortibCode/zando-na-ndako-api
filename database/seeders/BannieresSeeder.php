<?php

namespace Database\Seeders;

use App\Models\BannierePublicitaire;
use App\Models\Vendeur;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BannieresSeeder extends Seeder
{
    public function run(): void
    {
        $vendeur = Vendeur::first();

        $bannieres = [
            [
                'id'          => (string) Str::uuid(),
                'vendeur_id'  => $vendeur?->id,
                'titre'       => 'Grand Arrivage Poisson Frais du Fleuve',
                'description' => 'Bénéficiez de -25% sur les Capitaines et Tilapias frais du Marché Total aujourd\'hui !',
                'image_url'   => 'https://images.unsplash.com/photo-1534483509719-3feaee7c30da?w=1000&auto=format&fit=crop',
                'type_cible'  => 'boutique',
                'cible_id'    => $vendeur?->id,
                'date_debut'  => now()->subDay(),
                'date_fin'    => now()->addDays(30),
                'statut'      => 'actif',
                'priorite'    => 10,
                'nombre_vues' => 142,
                'nombre_clics'=> 38,
            ],
            [
                'id'          => (string) Str::uuid(),
                'vendeur_id'  => null,
                'titre'       => 'Offre Spéciale Diaspora : Livré à vos Proches',
                'description' => 'Payez en Euro ou Dollar depuis l\'étranger et faites livrer vos proches à Brazzaville en 2h !',
                'image_url'   => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=1000&auto=format&fit=crop',
                'type_cible'  => 'categorie',
                'cible_id'    => 'epicier-produits-alimentaires',
                'date_debut'  => now()->subDay(),
                'date_fin'    => now()->addDays(60),
                'statut'      => 'actif',
                'priorite'    => 5,
                'nombre_vues' => 280,
                'nombre_clics'=> 64,
            ],
            [
                'id'          => (string) Str::uuid(),
                'vendeur_id'  => $vendeur?->id,
                'titre'       => 'Fast-Food & Grillades du Soir',
                'description' => 'Livraison rapide à domicile à Brazzaville & Pointe-Noire jusqu\'à 23h !',
                'image_url'   => 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=1000&auto=format&fit=crop',
                'type_cible'  => 'boutique',
                'cible_id'    => $vendeur?->id,
                'date_debut'  => now()->subDay(),
                'date_fin'    => now()->addDays(45),
                'statut'      => 'actif',
                'priorite'    => 8,
                'nombre_vues' => 95,
                'nombre_clics'=> 21,
            ],
        ];

        foreach ($bannieres as $b) {
            BannierePublicitaire::updateOrCreate(['id' => $b['id']], $b);
        }
    }
}
