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
        // `type` référence une des valeurs de App\Models\Vendeur::TYPES_BOUTIQUE (liste canonique,
        // pas une clé étrangère : ce n'est pas une table à part, juste un texte contrôlé côté
        // validation) — une ligne n'existe ici que si un admin a réellement envoyé un logo pour ce
        // type, pas un logo par défaut inventé.
        Schema::create('types_boutique_logos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->unique();
            $table->string('logo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('types_boutique_logos');
    }
};
