<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignUuid('coupon_id')->nullable()->after('mode_attribution')->constrained('coupons')->nullOnDelete();
            $table->decimal('montant_reduction', 15, 2)->default(0)->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('montant_reduction');
        });
    }
};
