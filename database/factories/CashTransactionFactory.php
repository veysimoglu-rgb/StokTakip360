<?php

namespace Database\Factories;

use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashTransaction>
 */
class CashTransactionFactory extends Factory
{
    protected $model = CashTransaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'manual_in',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => now(),
        ];
    }
}
