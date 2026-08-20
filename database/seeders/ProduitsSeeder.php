<?php

namespace Database\Seeders;

use App\Models\Produit;
use App\Models\Vendeur;
use App\Models\Categorie;
use Illuminate\Database\Seeder;

class ProduitsSeeder extends Seeder
{
    public function run(): void
    {
        $vendeur   = Vendeur::first();
        $categories= Categorie::all()->keyBy('nom_categorie');

        if (!$vendeur) return;

        $produits = [
            [
                'nom_produit'          => 'Feuilles de Manioc (Pondu)',
                'categorie_id'         => $categories['Légumes & Feuilles']?->id ?? Categorie::first()?->id,
                'description'          => 'Feuilles de manioc fraîches pillées pour la préparation du Pondu traditionnel.',
                'prix_unitaire'        => 2500,
                'unite_mesure'         => 'Sachet',
                'quantite_stock'       => 50,
                'statut_disponibilite' => 'disponible',
                'type_fraicheur'       => 'frais',
                'photo_produit'        => 'https://images.unsplash.com/photo-1576045057995-568f588f82fb?auto=format&fit=crop&w=600&q=85',
            ],
            [
                'nom_produit'          => 'Poisson Thomson Frais',
                'categorie_id'         => $categories['Poissons & Viandes']?->id ?? Categorie::first()?->id,
                'description'          => 'Poisson frais de rivière de qualité supérieure.',
                'prix_unitaire'        => 8500,
                'unite_mesure'         => 'Kg',
                'quantite_stock'       => 30,
                'statut_disponibilite' => 'disponible',
                'type_fraicheur'       => 'frais',
                'photo_produit'        => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=600&q=85',
            ],
            [
                'nom_produit'          => 'Banane Plantain (Makemba)',
                'categorie_id'         => $categories['Tubercules & Féculents']?->id ?? Categorie::first()?->id,
                'description'          => 'Régime de bananes plantains bien mûres.',
                'prix_unitaire'        => 5000,
                'unite_mesure'         => 'Régime',
                'quantite_stock'       => 20,
                'statut_disponibilite' => 'disponible',
                'type_fraicheur'       => 'frais',
                'photo_produit'        => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=600&q=85',
            ],
            [
                'nom_produit'          => 'Piment Rouge (Pili-Pili)',
                'categorie_id'         => $categories['Épices & Condiments']?->id ?? Categorie::first()?->id,
                'description'          => 'Piment rouge très fort.',
                'prix_unitaire'        => 1000,
                'unite_mesure'         => 'Boîte',
                'quantite_stock'       => 100,
                'statut_disponibilite' => 'disponible',
                'type_fraicheur'       => 'frais',
                'photo_produit'        => 'https://images.unsplash.com/photo-1583119022894-919a68a3d0e3?auto=format&fit=crop&w=600&q=85',
            ],
        ];

        foreach ($produits as $prod) {
            Produit::updateOrCreate(
                ['nom_produit' => $prod['nom_produit']],
                array_merge($prod, ['vendeur_id' => $vendeur->id, 'date_maj_prix' => now()])
            );
        }
    }
}

