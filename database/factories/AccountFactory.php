<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Account::generateCode(),
            'type' => 'customer',
            'name' => $this->faker->company(),
            'active' => true,
        ];
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['type' => 'supplier']);
    }

    public function other(): static
    {
        return $this->state(fn () => ['type' => 'other']);
    }
}
