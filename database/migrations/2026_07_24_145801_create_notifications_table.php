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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('titre');
            $table->text('message');
            $table->enum('type_canal', ['push', 'sms', 'email']);
            $table->boolean('statut_lecture')->default(false);
            $table->timestamp('date_envoi')->useCurrent();
            $table->string('lien_action')->nullable();
            $table->enum('statut_envoi', ['envoye', 'echoue'])->default('envoye');
            $table->boolean('canal_secours_utilise')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
