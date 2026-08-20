<?php

namespace Database\Seeders;

use App\Models\TauxChange;
use Illuminate\Database\Seeder;

class TauxChangeSeeder extends Seeder
{
    public function run(): void
    {
        $taux = [
            ['devise_source' => 'USD', 'devise_cible' => 'FCFA', 'valeur_taux' => 2800.000000, 'source_taux' => 'Banca Centrale'],
            ['devise_source' => 'EUR', 'devise_cible' => 'FCFA', 'valeur_taux' => 3000.000000, 'source_taux' => 'Banca Centrale'],
            ['devise_source' => 'GBP', 'devise_cible' => 'FCFA', 'valeur_taux' => 3500.000000, 'source_taux' => 'Banca Centrale'],
            ['devise_source' => 'CAD', 'devise_cible' => 'FCFA', 'valeur_taux' => 2050.000000, 'source_taux' => 'Banca Centrale'],
        ];

        foreach ($taux as $t) {
            TauxChange::firstOrCreate(
                ['devise_source' => $t['devise_source'], 'devise_cible' => $t['devise_cible']],
                array_merge($t, ['date_maj' => now()])
            );
        }
    }
}

