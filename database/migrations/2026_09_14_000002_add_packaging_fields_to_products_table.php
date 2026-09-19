<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('package_label')->nullable()->after('unit');
            $table->unsignedInteger('package_qty')->nullable()->after('package_label');
            $table->string('subunit_label')->nullable()->after('package_qty');
            $table->unsignedInteger('subunit_to_base_qty')->nullable()->after('subunit_label');
            $table->decimal('package_weight_kg', 10, 3)->nullable()->after('subunit_to_base_qty');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'package_label', 'package_qty', 'subunit_label',
                'subunit_to_base_qty', 'package_weight_kg',
            ]);
        });
    }
};
