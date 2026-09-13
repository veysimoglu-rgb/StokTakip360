<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only, on all four tables. NOT NULL DEFAULT 'TL' means every
     * existing row is backfilled to 'TL' automatically by the database at
     * ALTER TABLE time — no manual UPDATE needed, and no ambiguity: every
     * sale/purchase/account_transaction/cash_transaction ever created in
     * this system was, in fact, TL (no other currency workflow has existed
     * until now). This mirrors the exact pattern already proven safe by
     * 2026_09_01_175640_add_currency_to_products_table, which added
     * currency the same way to a table that already had rows.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('currency', 3)->default('TL')->after('total');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('currency', 3)->default('TL')->after('total');
        });

        Schema::table('account_transactions', function (Blueprint $table) {
            $table->string('currency', 3)->default('TL')->after('amount');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('currency', 3)->default('TL')->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('account_transactions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
