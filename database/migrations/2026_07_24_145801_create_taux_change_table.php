<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('taux_change', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('devise_source', 10);
            $table->string('devise_cible', 10);
            $table->decimal('valeur_taux', 15, 6);
            $table->timestamp('date_maj')->useCurrent();
            $table->string('source_taux')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taux_change');
    }
};
