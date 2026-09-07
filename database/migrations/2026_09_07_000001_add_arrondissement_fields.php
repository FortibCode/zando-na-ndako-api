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
        if (Schema::hasTable('adresses_livraison')) {
            Schema::table('adresses_livraison', function (Blueprint $table) {
                if (!Schema::hasColumn('adresses_livraison', 'arrondissement')) {
                    $table->string('arrondissement')->nullable()->after('ville');
                }
                if (!Schema::hasColumn('adresses_livraison', 'quartier_custom')) {
                    $table->string('quartier_custom')->nullable()->after('quartier');
                }
            });
        }

        if (Schema::hasTable('vendeurs')) {
            Schema::table('vendeurs', function (Blueprint $table) {
                if (!Schema::hasColumn('vendeurs', 'arrondissement')) {
                    $table->string('arrondissement')->nullable()->after('categorie_principale');
                }
                if (!Schema::hasColumn('vendeurs', 'quartier')) {
                    $table->string('quartier')->nullable()->after('arrondissement');
                }
                if (!Schema::hasColumn('vendeurs', 'quartier_custom')) {
                    $table->string('quartier_custom')->nullable()->after('quartier');
                }
            });
        }

        if (Schema::hasTable('beneficiaires')) {
            Schema::table('beneficiaires', function (Blueprint $table) {
                if (!Schema::hasColumn('beneficiaires', 'arrondissement')) {
                    $table->string('arrondissement')->nullable()->after('ville');
                }
                if (!Schema::hasColumn('beneficiaires', 'quartier_custom')) {
                    $table->string('quartier_custom')->nullable()->after('quartier');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('adresses_livraison')) {
            Schema::table('adresses_livraison', function (Blueprint $table) {
                $table->dropColumn(['arrondissement', 'quartier_custom']);
            });
        }

        if (Schema::hasTable('vendeurs')) {
            Schema::table('vendeurs', function (Blueprint $table) {
                $table->dropColumn(['arrondissement', 'quartier', 'quartier_custom']);
            });
        }

        if (Schema::hasTable('beneficiaires')) {
            Schema::table('beneficiaires', function (Blueprint $table) {
                $table->dropColumn(['arrondissement', 'quartier_custom']);
            });
        }
    }
};
