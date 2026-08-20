<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->enum('type_reduction', ['pourcentage', 'montant_fixe']);
            $table->decimal('valeur_reduction', 10, 2);
            $table->decimal('montant_minimum_commande', 15, 2)->default(0);
            $table->decimal('montant_maximum_reduction', 15, 2)->nullable();
            $table->timestamp('date_debut');
            $table->timestamp('date_fin');
            $table->integer('limite_utilisation_totale')->nullable();
            $table->integer('limite_utilisation_par_client')->nullable()->default(1);
            $table->boolean('statut_actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
