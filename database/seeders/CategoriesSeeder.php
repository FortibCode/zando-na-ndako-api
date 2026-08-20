<?php

namespace Database\Seeders;

use App\Models\Categorie;
use Illuminate\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['nom_categorie' => 'Légumes & Feuilles', 'icone' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=400&q=80'],
            ['nom_categorie' => 'Poissons & Viandes', 'icone' => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=400&q=80'],
            ['nom_categorie' => 'Tubercules & Féculents', 'icone' => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=400&q=80'],
            ['nom_categorie' => 'Épices & Condiments', 'icone' => 'https://images.unsplash.com/photo-1583119022894-919a68a3d0e3?auto=format&fit=crop&w=400&q=80'],
            ['nom_categorie' => 'Fruits Frais', 'icone' => 'https://images.unsplash.com/photo-1550258987-190a2d41a8ba?auto=format&fit=crop&w=400&q=80'],
            ['nom_categorie' => 'Céréales & Grains', 'icone' => 'https://images.unsplash.com/photo-1536304993881-ff6e9eefa2a6?auto=format&fit=crop&w=400&q=80'],
        ];

        foreach ($categories as $cat) {
            Categorie::updateOrCreate(['nom_categorie' => $cat['nom_categorie']], $cat);
        }
    }
}

