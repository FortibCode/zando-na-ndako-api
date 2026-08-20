<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les champs manquants à la table users :
     * date_naissance, sexe, ville, adresse
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_naissance')->nullable()->after('prenom');
            $table->enum('sexe', ['homme', 'femme'])->nullable()->after('date_naissance');
            $table->string('ville')->nullable()->after('pays_residence');
            $table->text('adresse')->nullable()->after('ville');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_naissance', 'sexe', 'ville', 'adresse']);
        });
    }
};