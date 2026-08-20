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
        Schema::create('code_otp', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('code');
            $table->enum('canal', ['sms', 'email']);
            $table->timestamp('date_generation')->useCurrent();
            $table->timestamp('date_expiration');
            $table->enum('statut', ['valide', 'utilise', 'expire'])->default('valide');
            $table->integer('nombre_tentatives')->default(0);
            $table->string('adresse_ip_demande')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_otp');
    }
};
