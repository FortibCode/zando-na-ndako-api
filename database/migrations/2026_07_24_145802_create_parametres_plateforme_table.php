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
        Schema::create('parametres_plateforme', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('cle')->unique();
            $table->text('valeur');
            $table->text('description')->nullable();
            $table->timestamp('date_maj')->useCurrent();
            $table->foreignUuid('admin_dernier_maj_id')->nullable()->constrained('administrateurs');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parametres_plateforme');
    }
};
