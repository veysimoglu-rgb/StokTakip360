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
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['sale', 'purchase', 'collection', 'payment', 'manual_debt', 'manual_credit']);
            $table->enum('direction', ['debit', 'credit']);
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->timestamp('transaction_date')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('account_transactions')->nullOnDelete();
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
        Schema::dropIfExists('account_transactions');
    }
};
