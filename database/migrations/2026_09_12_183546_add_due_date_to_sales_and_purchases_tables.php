<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: existing sales/purchases rows get due_date = NULL, no
     * backfill. Vadeli/kısmi sales created before this migration simply have
     * no due date until edited — they are not retroactively assigned one.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('sale_date');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('purchase_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
