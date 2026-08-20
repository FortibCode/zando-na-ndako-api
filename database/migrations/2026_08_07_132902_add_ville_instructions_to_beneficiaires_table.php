<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('beneficiaires', function (Blueprint $table) {
            $table->string('ville')->nullable()->after('nom');
            $table->text('instructions')->nullable()->after('coordonnees_gps');
        });

        // Raw SQL : évite la dépendance à doctrine/dbal requise par Blueprint::change().
        DB::statement('ALTER TABLE beneficiaires ALTER COLUMN relation DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE beneficiaires SET relation = 'non précisé' WHERE relation IS NULL");
        DB::statement('ALTER TABLE beneficiaires ALTER COLUMN relation SET NOT NULL');

        Schema::table('beneficiaires', function (Blueprint $table) {
            $table->dropColumn(['ville', 'instructions']);
        });
    }
};
