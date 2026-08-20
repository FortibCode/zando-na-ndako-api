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
        Schema::create('beneficiaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('nom');
            $table->string('telephone');
            $table->text('adresse');
            $table->string('quartier');
            $table->json('coordonnees_gps')->nullable();
            $table->timestamp('date_ajout')->useCurrent();
            $table->string('relation'); // maman, soeur, ami, etc.
            $table->boolean('est_defaut')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beneficiaires');
    }
};
