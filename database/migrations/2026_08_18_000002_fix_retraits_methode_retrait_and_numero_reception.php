<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * demanderRetrait() (vendeur ET livreur) écrit depuis toujours 'methode_retrait' avec les valeurs
 * mtn_momo/airtel_money/virement, et l'app mobile envoie aussi 'numero_reception' (le numéro qui
 * reçoit les fonds) — mais ces migrations initiales avaient laissé la colonne 'moyen_retrait'
 * (mobile_money/virement) sans 'numero_reception'. Résultat : toute demande de retrait échouait
 * avec une erreur SQL (colonne inexistante) au lieu d'être enregistrée.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['retraits_vendeurs', 'retraits_livreurs'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_moyen_retrait_check");
            DB::statement("ALTER TABLE {$table} RENAME COLUMN moyen_retrait TO methode_retrait");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_methode_retrait_check CHECK (methode_retrait IN ('mtn_momo', 'airtel_money', 'virement'))");
            DB::statement("ALTER TABLE {$table} ADD COLUMN numero_reception VARCHAR(255) NULL");
        }
    }

    public function down(): void
    {
        foreach (['retraits_vendeurs', 'retraits_livreurs'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN numero_reception");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_methode_retrait_check");
            DB::statement("ALTER TABLE {$table} RENAME COLUMN methode_retrait TO moyen_retrait");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_moyen_retrait_check CHECK (moyen_retrait IN ('mobile_money', 'virement'))");
        }
    }
};
