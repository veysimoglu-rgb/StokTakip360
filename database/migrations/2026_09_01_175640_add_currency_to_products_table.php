<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('currency', 3)->default('TL')->after('sale_price');
        });

        $defaultCurrency = DB::table('settings')->where('key', 'currency')->value('value');

        if ($defaultCurrency) {
            DB::table('products')->update(['currency' => $defaultCurrency]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
