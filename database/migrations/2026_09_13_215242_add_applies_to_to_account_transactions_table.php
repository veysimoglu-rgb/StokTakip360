<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: a second, independent morph pair separate from the
     * existing `source_type`/`source_id` (which already means "the paired
     * ledger row for cancellation cascading" for manual collection/payment
     * rows, and "the Sale/Purchase itself" for sale/purchase-created rows).
     * `applies_to_type`/`applies_to_id` instead records which open Sale or
     * Purchase a manual collection/payment was applied against, so that
     * document's paid_amount can be updated and, on cancellation, reversed.
     * Existing rows get NULL (not linked to any specific document), which
     * is exactly today's behavior — no backfill.
     */
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->nullableMorphs('applies_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->dropMorphs('applies_to');
        });
    }
};
