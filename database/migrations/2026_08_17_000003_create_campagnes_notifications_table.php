<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('titre', 150);
            $table->text('message');
            $table->enum('cible_type', ['tous', 'clients', 'vendeurs', 'livreurs', 'diaspora']);
            $table->boolean('envoyer_push')->default(true);
            $table->boolean('envoyer_sms')->default(false);
            $table->string('lien_action')->nullable();
            $table->timestamp('date_envoi')->nullable();
            $table->enum('statut', ['programmee', 'envoyee', 'echouee'])->default('programmee');
            $table->integer('nombre_destinataires')->default(0);
            $table->integer('nombre_envois_reussis')->default(0);
            $table->foreignUuid('admin_id')->nullable()->constrained('administrateurs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignUuid('campagne_id')->nullable()->after('user_id')->constrained('campagnes_notifications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campagne_id');
        });
        Schema::dropIfExists('campagnes_notifications');
    }
};
