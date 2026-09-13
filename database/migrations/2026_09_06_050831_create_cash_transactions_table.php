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
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['collection', 'payment', 'other_income', 'expense', 'manual_in', 'manual_out']);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->timestamp('transaction_date')->useCurrent();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('cash_transactions')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->nullableMorphs('source');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
