<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * traiterLitige() valide 'decision' comme obligatoire depuis toujours mais ne l'écrivait que dans
 * le log d'audit — le texte tapé par l'admin n'était jamais réellement enregistré sur le litige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('litiges', function (Blueprint $table) {
            $table->text('decision')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('litiges', function (Blueprint $table) {
            $table->dropColumn('decision');
        });
    }
};
