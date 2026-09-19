<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('package_qty_input', 12, 3)->nullable()->after('quantity');
            $table->unsignedInteger('unit_multiplier_snapshot')->nullable()->after('package_qty_input');
            $table->decimal('line_weight_kg', 12, 3)->nullable()->after('unit_multiplier_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['package_qty_input', 'unit_multiplier_snapshot', 'line_weight_kg']);
        });
    }
};
