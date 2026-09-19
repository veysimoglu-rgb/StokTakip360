<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Devreden Bakiye = the cari's balance (in the order's own currency) right
 * before the order was placed. It must honour reversals, collections and
 * currencies exactly like the account statement, and must never contain the
 * order's own debt/payment legs.
 */
class SaleCarriedBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function product(string $currency = 'TL', float $price = 100): Product
    {
        return Product::factory()->create(['current_stock' => 100000, 'sale_price' => $price, 'currency' => $currency]);
    }

    private function ledger(Account $account, string $type, float $amount, string $currency = 'TL'): void
    {
        $account->transactions()->create([
            'type' => $type,
            'direction' => AccountTransaction::TYPE_DIRECTIONS[$type],
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => now(),
            'user_id' => $this->admin->id,
        ]);
    }

    private function order(Account $account, Product $product, float $qty, string $payment = 'vadeli', array $extra = []): Sale
    {
        $this->actingAs($this->admin)->post('/sales', $extra + [
            'account_id' => $account->id,
            'payment_type' => $payment,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => (float) $product->sale_price]],
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    public function test_it_is_the_balance_before_the_order_and_excludes_the_orders_own_debt(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->ledger($customer, 'manual_debt', 12500);

        $sale = $this->order($customer, $this->product(), 300); // 30.000 TL

        $this->assertSame(12500.0, $sale->carriedBalance());
        $this->assertSame(42500.0, $customer->fresh()->balance());
        $this->assertSame(42500.0, $sale->carriedBalance() + (float) $sale->total);
    }

    public function test_first_order_of_a_new_customer_has_a_zero_carried_balance(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->assertSame(0.0, $this->order($customer, $this->product(), 5)->carriedBalance());
    }

    public function test_a_paid_in_full_order_does_not_count_its_own_debt_or_payment_legs(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->ledger($customer, 'manual_debt', 1000);

        $sale = $this->order($customer, $this->product(), 5, 'pesin'); // debt 500 + payment 500

        $this->assertSame(1000.0, $sale->carriedBalance());
        $this->assertNotNull($sale->debt_account_transaction_id);
        $this->assertNotNull($sale->payment_account_transaction_id);
    }

    public function test_a_partial_payment_order_excludes_both_of_its_legs(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->ledger($customer, 'manual_debt', 250);

        $sale = $this->order($customer, $this->product(), 10, 'kismi', ['paid_amount' => 400]);

        $this->assertSame(250.0, $sale->carriedBalance());
        $this->assertSame(850.0, $customer->fresh()->balance()); // 250 + 1000 - 400
    }

    public function test_collections_before_the_order_reduce_the_carried_balance(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->ledger($customer, 'manual_debt', 5000);
        $this->ledger($customer, 'collection', 2000);

        $this->assertSame(3000.0, $this->order($customer, $this->product(), 1)->carriedBalance());
    }

    public function test_a_cancelled_earlier_sale_nets_to_zero_through_its_reversal_rows(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $earlier = $this->order($customer, $product, 10); // 1000 borç
        $this->actingAs($this->admin)->post("/sales/{$earlier->id}/cancel")->assertRedirect();
        $this->assertSame(0.0, $customer->fresh()->balance());

        $this->assertSame(0.0, $this->order($customer, $product, 3)->carriedBalance());
    }

    public function test_an_uncancelled_earlier_sale_is_carried(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->order($customer, $product, 10); // 1000
        $second = $this->order($customer, $product, 3);

        $this->assertSame(1000.0, $second->carriedBalance());
    }

    public function test_balances_are_per_currency(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->ledger($customer, 'manual_debt', 500, 'TL');
        $this->ledger($customer, 'manual_debt', 100, 'USD');
        $this->ledger($customer, 'collection', 20, 'USD');

        $tl = $this->order($customer, $this->product('TL', 10), 1);
        $usd = $this->order($customer, $this->product('USD', 10), 1);

        $this->assertSame(500.0, $tl->carriedBalance());
        $this->assertSame(80.0, $usd->carriedBalance());
    }

    public function test_the_orders_own_debt_is_not_mixed_into_another_currency(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->order($customer, $this->product('TL', 10), 5); // 50 TL borç

        $usdOrder = $this->order($customer, $this->product('USD', 10), 1);

        $this->assertSame(0.0, $usdOrder->carriedBalance());
    }

    public function test_another_customers_ledger_never_leaks_in(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $other = Account::factory()->create(['type' => 'customer']);
        $this->ledger($other, 'manual_debt', 9999);
        $this->ledger($customer, 'manual_debt', 100);

        $this->assertSame(100.0, $this->order($customer, $this->product(), 1)->carriedBalance());
    }

    public function test_an_order_without_a_cari_has_no_carried_balance(): void
    {
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $this->assertNull(Sale::first()->carriedBalance());
    }

    public function test_later_activity_does_not_shift_an_existing_orders_carried_balance(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();
        $this->ledger($customer, 'manual_debt', 1000);

        $first = $this->order($customer, $product, 5);
        $this->assertSame(1000.0, $first->carriedBalance());

        // sonradan: tahsilat + yeni sipariş + yeni manuel borç
        $this->actingAs($this->admin)->post("/accounts/{$customer->id}/collect", ['amount' => 300, 'currency' => 'TL']);
        $this->order($customer, $product, 2);
        $this->ledger($customer, 'manual_debt', 777);

        $this->assertSame(1000.0, $first->fresh()->carriedBalance());
    }

    public function test_editing_the_order_does_not_shift_its_carried_balance(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();
        $this->ledger($customer, 'manual_debt', 1000);

        $sale = $this->order($customer, $product, 5);
        $this->actingAs($this->admin)->post("/accounts/{$customer->id}/collect", ['amount' => 200, 'currency' => 'TL', 'sale_id' => $sale->id]);

        $this->actingAs($this->admin)->put("/sales/{$sale->id}", [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 8, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame(1000.0, $sale->carriedBalance());
        $this->assertSame(800.0, (float) $sale->total);
        // cari: 1000 + 800 (yeni borç) - 200 (tahsilat)
        $this->assertSame(1600.0, $customer->fresh()->balance());
    }

    public function test_the_receipt_prints_carried_and_total_balance_for_the_order_currency(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $this->ledger($customer, 'manual_debt', 12500);
        $this->ledger($customer, 'manual_debt', 40, 'USD');
        $sale = $this->order($customer, $this->product('TL', 100), 300);

        $this->actingAs($this->admin)->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertSeeInOrder(['Sipariş Toplamı', '30.000,00 TL', 'Devreden Bakiye', '12.500,00 TL', 'Toplam Bakiye', '42.500,00 TL']);
    }
}
