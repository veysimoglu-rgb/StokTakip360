<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Purely additive/nullable: existing rows get NULL in every new column,
     * and existing code (StockMovementController::storeIn/storeOut) never
     * writes to them, so current stock-in/out behavior is unaffected.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->nullableMorphs('source');
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropColumn(['cancelled_at']);
            $table->dropMorphs('source');
        });
    }
};
