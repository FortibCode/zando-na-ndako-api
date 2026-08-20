<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE livreurs DROP CONSTRAINT IF EXISTS livreurs_statut_disponibilite_check');
        DB::table('livreurs')->where('statut_disponibilite', 'en_ligne')->update(['statut_disponibilite' => 'disponible']);
        DB::table('livreurs')->where('statut_disponibilite', 'hors_ligne')->update(['statut_disponibilite' => 'indisponible']);
        DB::statement("ALTER TABLE livreurs ADD CONSTRAINT livreurs_statut_disponibilite_check CHECK (statut_disponibilite IN ('disponible', 'indisponible'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE livreurs DROP CONSTRAINT IF EXISTS livreurs_statut_disponibilite_check');
        DB::table('livreurs')->where('statut_disponibilite', 'disponible')->update(['statut_disponibilite' => 'en_ligne']);
        DB::table('livreurs')->where('statut_disponibilite', 'indisponible')->update(['statut_disponibilite' => 'hors_ligne']);
        DB::statement("ALTER TABLE livreurs ADD CONSTRAINT livreurs_statut_disponibilite_check CHECK (statut_disponibilite IN ('en_ligne', 'hors_ligne'))");
    }
};
