<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Son satış fiyatı": the new-order form pre-fills a line's unit price with
 * what the same customer really paid last time for the same product in the
 * same currency (order discount included). Only a suggestion — the price
 * stays freely editable and the ledgers are never involved.
 */
class SaleLastPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function product(string $currency = 'TL', float $listPrice = 1.75): Product
    {
        return Product::factory()->create(['current_stock' => 1000000, 'sale_price' => $listPrice, 'currency' => $currency]);
    }

    private function customer(string $name = 'ABC Plastik'): Account
    {
        return Account::factory()->create(['type' => 'customer', 'name' => $name]);
    }

    private function order(Account $customer, Product $product, float $qty, float $price, float $discount = 0, array $extra = []): Sale
    {
        $this->actingAs($this->admin)->post('/sales', $extra + [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_total' => $discount,
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $price]],
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    /** @return array<string, mixed> the price entry for the product, or [] when none was found */
    private function lookup(Account $customer, Product $product, ?Sale $except = null): array
    {
        $response = $this->actingAs($this->admin)->getJson(route('sales.last-prices', array_filter([
            'account_id' => $customer->id,
            'product_ids' => [$product->id],
            'except_sale_id' => $except?->id,
        ])))->assertOk();

        return $response->json("prices.{$product->id}") ?? [];
    }

    public function test_same_customer_product_and_currency_returns_the_last_price_with_order_number_and_date(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $sale = $this->order($customer, $product, 100, 1.50, extra: ['sale_date' => '2026-09-25']);

        $found = $this->lookup($customer, $product);

        $this->assertSame(1.5, $found['unit_price']);
        $this->assertSame('1,50 TL', $found['unit_price_text']);
        $this->assertSame($sale->number, $found['number']);
        $this->assertSame('25.09.2026', $found['date']);
        $this->assertSame('TL', $found['currency']);
    }

    public function test_the_most_recent_order_wins_by_order_date(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->order($customer, $product, 10, 1.20, extra: ['sale_date' => '2026-09-01']);
        $latest = $this->order($customer, $product, 10, 1.60, extra: ['sale_date' => '2026-09-20']);
        // Entered last, but dated earlier: it is not the latest order.
        $this->order($customer, $product, 10, 0.90, extra: ['sale_date' => '2026-08-15']);

        $found = $this->lookup($customer, $product);

        $this->assertSame(1.6, $found['unit_price']);
        $this->assertSame($latest->number, $found['number']);
    }

    public function test_another_customers_price_is_never_used(): void
    {
        $product = $this->product();
        $this->order($this->customer('Başka Müşteri'), $product, 10, 1.10);

        $this->assertSame([], $this->lookup($this->customer('ABC Plastik'), $product));
    }

    public function test_a_price_in_another_currency_is_never_used(): void
    {
        $customer = $this->customer();
        $product = $this->product('TL');
        $this->order($customer, $product, 10, 1.50);

        // The product is now priced in USD: the earlier TL price must not leak into it.
        $product->update(['currency' => 'USD']);

        $this->assertSame([], $this->lookup($customer, $product));

        $this->order($customer, $product, 10, 0.05);
        $found = $this->lookup($customer, $product);

        $this->assertSame(0.05, $found['unit_price']);
        $this->assertSame('USD', $found['currency']);
        $this->assertSame('0,05 USD', $found['unit_price_text']);
    }

    public function test_only_the_requested_product_is_matched(): void
    {
        $customer = $this->customer();
        $this->order($customer, $this->product(), 10, 1.50);

        $this->assertSame([], $this->lookup($customer, $this->product()));
    }

    // 10.000 × 1,50 = 15.000; %10 iskonto sonrası gerçek fiyat 1,35
    public function test_a_discounted_order_yields_the_real_applied_price_not_the_list_price(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->order($customer, $product, 10000, 1.50, discount: 1500);

        $found = $this->lookup($customer, $product);

        $this->assertSame(1.35, $found['unit_price']);
        $this->assertSame('1,35 TL', $found['unit_price_text']);
        // The saved line itself keeps the list price; only the suggestion is discounted.
        $this->assertSame('1.50', SaleItem::first()->unit_price);
    }

    public function test_the_order_discount_is_spread_proportionally_over_a_multi_line_order(): void
    {
        $customer = $this->customer();
        $a = $this->product();
        $b = $this->product();

        // 100 × 2,00 + 50 × 4,00 = 400; 40 discount = 10 % off every line.
        $this->actingAs($this->admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_total' => 40,
            'items' => [
                ['product_id' => $a->id, 'quantity' => 100, 'unit_price' => 2],
                ['product_id' => $b->id, 'quantity' => 50, 'unit_price' => 4],
            ],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)->getJson(route('sales.last-prices', [
            'account_id' => $customer->id, 'product_ids' => [$a->id, $b->id],
        ]))->assertOk();

        $this->assertSame(1.8, $response->json("prices.{$a->id}.unit_price"));
        $this->assertSame(3.6, $response->json("prices.{$b->id}.unit_price"));
    }

    public function test_a_cancelled_last_order_is_skipped_in_favour_of_the_previous_valid_one(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $valid = $this->order($customer, $product, 10, 1.40, extra: ['sale_date' => '2026-09-01']);
        $cancelled = $this->order($customer, $product, 10, 2.20, extra: ['sale_date' => '2026-09-20']);

        $this->actingAs($this->admin)->post(route('sales.cancel', $cancelled))->assertSessionHasNoErrors();

        $found = $this->lookup($customer, $product);

        $this->assertSame(1.4, $found['unit_price']);
        $this->assertSame($valid->number, $found['number']);
    }

    public function test_when_every_earlier_order_is_cancelled_nothing_is_suggested(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $sale = $this->order($customer, $product, 10, 1.40);

        $this->actingAs($this->admin)->post(route('sales.cancel', $sale));

        $this->assertSame([], $this->lookup($customer, $product));
    }

    public function test_no_sales_history_returns_an_empty_result(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('sales.last-prices', [
            'account_id' => $this->customer()->id, 'product_ids' => [$this->product()->id],
        ]))->assertOk();

        $this->assertSame([], $response->json('prices'));
    }

    public function test_the_edited_order_can_be_left_out_of_the_lookup(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $older = $this->order($customer, $product, 10, 1.20, extra: ['sale_date' => '2026-09-01']);
        $editing = $this->order($customer, $product, 10, 1.90, extra: ['sale_date' => '2026-09-20']);

        $this->assertSame($editing->number, $this->lookup($customer, $product)['number']);
        $this->assertSame($older->number, $this->lookup($customer, $product, except: $editing)['number']);
    }

    public function test_an_edited_orders_own_edit_keeps_it_the_latest_valid_order(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $sale = $this->order($customer, $product, 100, 1.50);

        $this->actingAs($this->admin)->put("/sales/{$sale->id}", [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'discount_total' => 15,
            'items' => [['product_id' => $product->id, 'quantity' => 100, 'unit_price' => 1.50]],
        ])->assertSessionHasNoErrors();

        // Edit recreates the lines under the same order: subtotal 150, discount 15 -> 1,35.
        $found = $this->lookup($customer, $product);
        $this->assertSame(1.35, $found['unit_price']);
        $this->assertSame($sale->number, $found['number']);
    }

    public function test_the_lookup_is_admin_only_like_order_entry_and_needs_a_login(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $query = ['account_id' => $customer->id, 'product_ids' => [$product->id]];

        $this->get(route('sales.last-prices', $query))->assertRedirect('/login');

        $staff = User::factory()->create();
        $staff->assignRole('Personel');
        $this->actingAs($staff)->get(route('sales.last-prices', $query))->assertForbidden();
    }

    public function test_the_lookup_validates_its_input(): void
    {
        $this->actingAs($this->admin)->getJson(route('sales.last-prices'))->assertStatus(422);
        $this->actingAs($this->admin)->getJson(route('sales.last-prices', ['account_id' => 999999, 'product_ids' => [1]]))->assertStatus(422);
    }

    // Fiyat yalnızca varsayılan: kullanıcı elle değiştirirse kaydedilen fiyat elle girilendir
    public function test_a_manually_changed_price_is_saved_as_typed(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->order($customer, $product, 100, 1.50);

        $second = $this->order($customer, $product, 100, 2.25);

        $this->assertSame('2.25', $second->items()->first()->unit_price);
        $this->assertSame('225.00', $second->total);
    }

    public function test_looking_up_a_price_writes_nothing(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->order($customer, $product, 100, 1.50);
        $counts = [Sale::count(), SaleItem::count(), \App\Models\StockMovement::count(), \App\Models\AccountTransaction::count()];

        $this->lookup($customer, $product);

        $this->assertSame($counts, [Sale::count(), SaleItem::count(), \App\Models\StockMovement::count(), \App\Models\AccountTransaction::count()]);
    }

    // Form: müşteri ve ürün değişiminde arama, fiyat altında yardımcı bilgi, elle değişiklik korunur
    public function test_the_new_order_form_wires_the_lookup_and_the_helper_line(): void
    {
        $response = $this->actingAs($this->admin)->get(route('sales.create'))->assertOk();

        $response->assertSee(route('sales.last-prices'), false);
        $response->assertSee('x-model="accountId" @change="onAccountChange()"', false);
        $response->assertSee('fetchLastPrices(', false);
        $response->assertSeeInOrder(['data-last-sale', 'Son satış:', 'item.last.unit_price_text', 'item.last.number', 'item.last.date'], false);
        // Typing a price marks it as the user's own so a lookup never overwrites it.
        $response->assertSee('@input="item.price_touched = true"', false);
        // No lookup happens on load of a new order and nothing is pre-selected.
        $response->assertSee('exceptSaleId: null', false);
    }

    // Düzenleme ekranı: kayıtlı fiyatlar "elle girilmiş" sayılır, kendi siparişi aramadan hariç tutulur
    public function test_the_edit_form_keeps_saved_prices_and_excludes_its_own_order(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $sale = $this->order($customer, $product, 100, 1.50);

        $response = $this->actingAs($this->admin)->get(route('sales.edit', $sale))->assertOk();

        $response->assertSee('exceptSaleId: '.$sale->id, false);
        $response->assertSee('price_touched', false);
        // Saved line as the form receives it: its own price, flagged as the user's (Js::from hex-encodes the quotes).
        $response->assertSee('\\u0022unit_price\\u0022:1.5,', false);
        $response->assertSee('\\u0022price_touched\\u0022:true', false);
        $response->assertSee("accountId: '{$customer->id}'", false);
        // The saved data itself is untouched by merely opening the form.
        $this->assertSame('1.50', $sale->fresh()->items()->first()->unit_price);
    }
}
