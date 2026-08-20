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
        Schema::create('commandes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero_commande')->unique();
            $table->foreignUuid('client_id')->constrained('clients');
            $table->foreignUuid('vendeur_id')->nullable()->constrained('vendeurs');
            $table->foreignUuid('livreur_id')->nullable()->constrained('livreurs');
            $table->foreignUuid('beneficiaire_id')->nullable()->constrained('beneficiaires');
            $table->timestamp('date_commande')->useCurrent();
            $table->enum('statut_commande', [
                'confirmee', 'achat_marche', 'preparation', 'en_route', 'livree', 'annulee'
            ])->default('confirmee');
            $table->enum('mode_attribution', ['auto', 'manuelle'])->default('auto');
            $table->decimal('montant_sous_total', 15, 2);
            $table->decimal('frais_livraison', 10, 2);
            $table->decimal('montant_total', 15, 2);
            $table->timestamp('creneau_livraison_debut')->nullable();
            $table->timestamp('creneau_livraison_fin')->nullable();
            $table->text('adresse_livraison');
            $table->json('coordonnees_gps_livraison')->nullable();
            $table->enum('type_commande', ['locale', 'diaspora'])->default('locale');
            $table->string('devise_paiement')->default('FCFA');
            $table->string('motif_annulation')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commandes');
    }
};
