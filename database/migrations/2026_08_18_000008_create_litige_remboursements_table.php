<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité des remboursements déclenchés par un litige, découplée de toute passerelle de
 * paiement réelle : reste 'en_attente' pour tout ce qui n'est pas Stripe/PayPal (mtn_momo et
 * airtel_money ont une vraie intégration Collections — voir MtnMomoService/AirtelMoneyService —
 * mais cette API ne couvre pas le remboursement ; paiement_livraison n'a jamais de paiement
 * électronique à rembourser — voir AdminController::rembourserCommande()), exactement comme les
 * retraits vendeurs/livreurs sont déjà traités manuellement hors-app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('litige_remboursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('litige_id')->constrained('litiges')->cascadeOnDelete();
            $table->foreignUuid('decision_id')->nullable()->constrained('litige_decisions')->nullOnDelete();
            $table->decimal('montant', 12, 2);
            $table->string('devise')->default('FCFA');
            $table->string('statut')->default('en_attente');
            $table->string('methode_prevue')->nullable();
            $table->string('reference_externe')->nullable();
            $table->timestamps();

            $table->index('litige_id');
        });

        DB::statement("ALTER TABLE litige_remboursements ADD CONSTRAINT litige_remboursements_statut_check CHECK (statut IN ('en_attente', 'en_traitement', 'termine', 'echoue', 'annule'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('litige_remboursements');
    }
};
