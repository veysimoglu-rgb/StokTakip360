<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive-only migration: adds a nullable currency column.
     * Existing rows are intentionally left NULL — the currency of a past
     * movement cannot be known retroactively, so it must never be guessed
     * or backfilled from the product's current currency.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
