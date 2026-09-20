<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleEditTest extends TestCase
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

    private function product(float $stock = 100000, float $price = 1): Product
    {
        return Product::factory()->create(['current_stock' => $stock, 'sale_price' => $price, 'currency' => 'TL']);
    }

    private function credit(Account $customer, Product $product, float $qty, float $price = 1, array $extra = []): Sale
    {
        $this->actingAs($this->admin)->post('/sales', $extra + [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $price]],
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    private function update(Sale $sale, array $payload)
    {
        return $this->actingAs($this->admin)->put("/sales/{$sale->id}", $payload);
    }

    private function netOut(Product $product): float
    {
        $movements = StockMovement::where('product_id', $product->id);

        return (float) (clone $movements)->where('type', 'out')->sum('quantity')
            - (float) (clone $movements)->where('type', 'in')->sum('quantity');
    }

    private function creditPayload(Account $customer, array $items, array $extra = []): array
    {
        return $extra + [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => $items,
        ];
    }

    // Spesifikasyon örneği: 10.000 -> 12.000, yalnızca +2.000 fark stok/cariye yansır
    public function test_increasing_the_quantity_only_reflects_the_difference_and_creates_no_duplicate_sale(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100000);
        $sale = $this->credit($customer, $product, 10000);
        $number = $sale->number;

        $this->assertSame(90000.0, (float) $product->fresh()->current_stock);
        $this->assertSame(10000.0, $customer->fresh()->balance());

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 12000, 'unit_price' => 1],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('sales.show', $sale));

        $this->assertSame(1, Sale::count());
        $sale->refresh();
        $this->assertSame($number, $sale->number);
        $this->assertNotNull($sale->edited_at);
        $this->assertSame(12000.0, (float) $sale->total);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(12000.0, (float) $sale->items()->first()->quantity);

        $this->assertSame(88000.0, (float) $product->fresh()->current_stock);
        $this->assertSame(12000.0, $this->netOut($product));
        $this->assertSame(12000.0, $customer->fresh()->balance());
    }

    public function test_decreasing_the_quantity_gives_the_difference_back(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100000);
        $sale = $this->credit($customer, $product, 10000);

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 8000, 'unit_price' => 1],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(92000.0, (float) $product->fresh()->current_stock);
        $this->assertSame(8000.0, $this->netOut($product));
        $this->assertSame(8000.0, $customer->fresh()->balance());
        $this->assertSame(1, Sale::count());
    }

    public function test_changing_only_the_unit_price_keeps_stock_and_updates_the_cari_amount(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(1000, 10);
        $sale = $this->credit($customer, $product, 100, 10);

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 100, 'unit_price' => 12],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(900.0, (float) $product->fresh()->current_stock);
        $this->assertSame(1200.0, (float) $sale->fresh()->total);
        $this->assertSame(1200.0, $customer->fresh()->balance());
    }

    public function test_adding_and_removing_products(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $a = $this->product(100, 10);
        $b = $this->product(100, 20);
        $sale = $this->credit($customer, $a, 5, 10);

        // A çıkarılır, B eklenir
        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $b->id, 'quantity' => 3, 'unit_price' => 20],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(100.0, (float) $a->fresh()->current_stock);
        $this->assertSame(97.0, (float) $b->fresh()->current_stock);
        $this->assertSame(60.0, $customer->fresh()->balance());
        $this->assertSame(1, $sale->fresh()->items()->count());

        // İkisi birden
        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $a->id, 'quantity' => 2, 'unit_price' => 10],
            ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 20],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(98.0, (float) $a->fresh()->current_stock);
        $this->assertSame(99.0, (float) $b->fresh()->current_stock);
        $this->assertSame(40.0, $customer->fresh()->balance());
        $this->assertSame(2, $sale->fresh()->items()->count());
    }

    public function test_editing_twice_never_duplicates_the_net_effect(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(1000, 5);
        $sale = $this->credit($customer, $product, 100, 5);

        foreach ([120, 90, 100] as $qty) {
            $this->update($sale, $this->creditPayload($customer, [
                ['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 5],
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(1, Sale::count());
        $this->assertSame(900.0, (float) $product->fresh()->current_stock);
        $this->assertSame(500.0, $customer->fresh()->balance());
        $this->assertSame(100.0, $this->netOut($product));
    }

    public function test_cash_order_edit_replaces_the_cash_movement(): void
    {
        $product = $this->product(100, 10);
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10]],
        ]);
        $sale = Sale::first();
        $this->assertSame(50.0, CashTransaction::balance());

        $this->update($sale, [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 8, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(80.0, CashTransaction::balance());
        $this->assertSame('paid', $sale->fresh()->status);
        $this->assertSame(80.0, (float) $sale->fresh()->paid_amount);
        $this->assertSame(92.0, (float) $product->fresh()->current_stock);
    }

    public function test_partial_payment_order_edit_keeps_the_ledger_balanced(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 100);
        $this->actingAs($this->admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'kismi', 'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $sale = Sale::first();

        $this->update($sale, [
            'account_id' => $customer->id, 'payment_type' => 'kismi', 'paid_amount' => 500,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 12, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame(1200.0, (float) $sale->total);
        $this->assertSame(500.0, (float) $sale->paid_amount);
        $this->assertSame('partial', $sale->status);
        $this->assertSame(700.0, $customer->fresh()->balance());
        $this->assertSame(500.0, CashTransaction::balance());
        $this->assertNotNull($sale->debt_account_transaction_id);
        $this->assertNotNull($sale->payment_account_transaction_id);
        $this->assertNotNull($sale->cash_transaction_id);
    }

    // Sonradan cari ekranından yapılan tahsilat düzenlemede kaybolmaz
    public function test_a_later_collection_applied_to_the_order_survives_an_edit(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 100);
        $sale = $this->credit($customer, $product, 10, 100);

        $this->actingAs($this->admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 400, 'currency' => 'TL', 'sale_id' => $sale->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(400.0, (float) $sale->fresh()->paid_amount);

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 15, 'unit_price' => 100],
        ]))->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame(1500.0, (float) $sale->total);
        $this->assertSame(400.0, (float) $sale->paid_amount);
        $this->assertSame('partial', $sale->status);
        $this->assertSame(1100.0, $customer->fresh()->balance());
        $this->assertSame(400.0, CashTransaction::balance());
    }

    public function test_edit_is_refused_when_later_collections_would_exceed_the_new_total(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 100);
        $sale = $this->credit($customer, $product, 10, 100);
        $this->actingAs($this->admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 800, 'currency' => 'TL', 'sale_id' => $sale->id,
        ]);

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 100],
        ]))->assertSessionHas('error');

        // Hiçbir şey değişmedi
        $this->assertSame(1000.0, (float) $sale->fresh()->total);
        $this->assertSame(90.0, (float) $product->fresh()->current_stock);
        $this->assertSame(200.0, $customer->fresh()->balance());
    }

    public function test_a_failed_edit_rolls_everything_back(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 5, 10);
        $movementsBefore = StockMovement::count();
        $transactionsBefore = AccountTransaction::count();

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 8, 'unit_price' => 10],
        ], ['discount_total' => 999999]))->assertSessionHas('error');

        $this->assertSame($movementsBefore, StockMovement::count());
        $this->assertSame($transactionsBefore, AccountTransaction::count());
        $this->assertSame(95.0, (float) $product->fresh()->current_stock);
        $this->assertSame(50.0, $customer->fresh()->balance());
        $this->assertSame(50.0, (float) $sale->fresh()->total);
        $this->assertNull($sale->fresh()->edited_at);
    }

    public function test_a_cancelled_order_cannot_be_edited(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 5, 10);
        $this->actingAs($this->admin)->post("/sales/{$sale->id}/cancel");

        $this->actingAs($this->admin)->get("/sales/{$sale->id}/edit")->assertRedirect(route('sales.show', $sale));

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 8, 'unit_price' => 10],
        ]))->assertSessionHas('error');

        $this->assertSame(100.0, (float) $product->fresh()->current_stock);
        $this->assertSame(0.0, $customer->fresh()->balance());
    }

    public function test_an_edited_order_can_still_be_cancelled_and_fully_reverses(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 5, 10);

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 9, 'unit_price' => 10],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post("/sales/{$sale->id}/cancel")->assertRedirect();

        $this->assertTrue($sale->fresh()->isCancelled());
        $this->assertSame(100.0, (float) $product->fresh()->current_stock);
        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(0.0, $this->netOut($product));
    }

    // Düzenlemede yetersiz stok: onaysız reddedilir, onaylı kaydedilir ama stok negatife düşmez
    public function test_edit_with_insufficient_stock_needs_confirmation_and_never_goes_negative(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 1);
        $sale = $this->credit($customer, $product, 60);
        $this->assertSame(40.0, (float) $product->fresh()->current_stock);

        $payload = $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 150, 'unit_price' => 1],
        ]);

        $this->update($sale, $payload)->assertSessionHas('stock_warning');
        $this->assertSame(40.0, (float) $product->fresh()->current_stock);
        $this->assertSame(60.0, (float) $sale->fresh()->total);

        $this->update($sale, $payload + ['confirm_insufficient_stock' => 1])->assertSessionHasNoErrors();

        $sale->refresh();
        $item = $sale->items()->first();
        $this->assertSame(150.0, (float) $item->quantity);
        $this->assertSame(50.0, (float) $item->stock_shortfall_quantity);
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
        $this->assertSame(100.0, $this->netOut($product));
        $this->assertSame(150.0, $customer->fresh()->balance());
    }

    public function test_packaging_snapshot_is_replaced_on_edit(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = Product::factory()->create([
            'current_stock' => 100000, 'sale_price' => 2, 'currency' => 'TL',
            'package_label' => 'Balya', 'package_qty' => 15, 'subunit_label' => 'Paket',
            'subunit_to_base_qty' => 100, 'package_weight_kg' => 8.5,
        ]);
        $this->actingAs($this->admin)->post('/sales', $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 10, 'unit_price' => 2],
        ]))->assertSessionHasNoErrors();
        $sale = Sale::first();
        $this->assertSame(85.0, (float) $sale->items()->first()->line_weight_kg);

        $this->update($sale, $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 12, 'unit_price' => 2],
        ]))->assertSessionHasNoErrors();

        $item = $sale->fresh()->items()->first();
        $this->assertSame(12.0, (float) $item->package_qty_input);
        $this->assertSame(18000.0, (float) $item->quantity);
        $this->assertSame(102.0, (float) $item->line_weight_kg);
        $this->assertSame(1500, (int) $item->unit_multiplier_snapshot);
        $this->assertSame(82000.0, (float) $product->fresh()->current_stock);
    }

    public function test_the_edit_form_renders_prefilled_and_personel_cannot_use_it(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 5, 10);

        $this->actingAs($this->admin)->get("/sales/{$sale->id}/edit")
            ->assertOk()
            ->assertSee('Siparişi Güncelle')
            ->assertSee($sale->number)
            ->assertSee("paymentType: 'vadeli'", false);

        $personel = User::factory()->create();
        $personel->assignRole('Personel');
        $this->actingAs($personel)->get("/sales/{$sale->id}/edit")->assertForbidden();
        $this->actingAs($personel)->put("/sales/{$sale->id}", $this->creditPayload($customer, [
            ['product_id' => $product->id, 'quantity' => 8, 'unit_price' => 10],
        ]))->assertForbidden();
        $this->assertSame(50.0, (float) $sale->fresh()->total);
    }

    public function test_show_page_offers_edit_only_for_active_orders(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 5, 10);

        $this->actingAs($this->admin)->get("/sales/{$sale->id}")->assertOk()->assertSee(route('sales.edit', $sale), false);

        $this->actingAs($this->admin)->post("/sales/{$sale->id}/cancel");
        $this->actingAs($this->admin)->get("/sales/{$sale->id}")->assertOk()->assertDontSee(route('sales.edit', $sale), false);
    }

    // ---------------------------------------------------------------- later collections: customer / currency guard

    private function collect(Account $customer, Sale $sale, float $amount = 40): void
    {
        $this->actingAs($this->admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => $amount, 'currency' => 'TL', 'sale_id' => $sale->id,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    /** @return array<int, int> row counts of every ledger table, to prove a refused edit wrote nothing */
    private function ledgerCounts(): array
    {
        return [StockMovement::count(), AccountTransaction::count(), CashTransaction::count()];
    }

    public function test_the_customer_cannot_change_once_a_later_collection_exists(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $other = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 10, 10);
        $this->collect($customer, $sale);
        $before = $this->ledgerCounts();

        $this->update($sale, $this->creditPayload($other, [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]]))
            ->assertSessionHas('error');

        $this->assertStringContainsString('müşteri (cari) değiştirilemez', session('error'));
        $this->assertSame($before, $this->ledgerCounts());
        $this->assertSame($customer->id, $sale->fresh()->account_id);
        $this->assertNull($sale->fresh()->edited_at);
        $this->assertSame(60.0, $customer->fresh()->balance());
        $this->assertSame(0.0, $other->fresh()->balance());
        $this->assertSame(90.0, (float) $product->fresh()->current_stock);
    }

    public function test_removing_the_customer_is_blocked_too_when_a_later_collection_exists(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 10, 10);
        $this->collect($customer, $sale);

        $this->update($sale, ['payment_type' => 'pesin', 'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]]])
            ->assertSessionHas('error');

        $this->assertStringContainsString('müşteri (cari) değiştirilemez', session('error'));
        $this->assertSame($customer->id, $sale->fresh()->account_id);
        $this->assertSame(60.0, $customer->fresh()->balance());
    }

    public function test_the_currency_cannot_change_once_a_later_collection_exists(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $tl = $this->product(100, 10);
        $usd = Product::factory()->create(['current_stock' => 100, 'sale_price' => 10, 'currency' => 'USD']);
        $sale = $this->credit($customer, $tl, 10, 10);
        $this->collect($customer, $sale);
        $before = $this->ledgerCounts();

        $this->update($sale, $this->creditPayload($customer, [['product_id' => $usd->id, 'quantity' => 10, 'unit_price' => 10]]))
            ->assertSessionHas('error');

        $this->assertStringContainsString('para birimi değiştirilemez', session('error'));
        $this->assertSame($before, $this->ledgerCounts());
        $this->assertSame('TL', $sale->fresh()->currency);
        $this->assertSame(90.0, (float) $tl->fresh()->current_stock);
        $this->assertSame(100.0, (float) $usd->fresh()->current_stock);
        $this->assertSame(60.0, $customer->fresh()->balance());
        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('USD'));
    }

    public function test_the_guards_run_before_a_stock_shortage_warning(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $other = Account::factory()->create(['type' => 'customer']);
        $tl = $this->product(100, 10);
        $usd = Product::factory()->create(['current_stock' => 5, 'sale_price' => 10, 'currency' => 'USD']);
        $sale = $this->credit($customer, $tl, 10, 10);
        $this->collect($customer, $sale);

        // customer change + a quantity far beyond stock -> the customer message, not a stock warning
        $this->update($sale, $this->creditPayload($other, [['product_id' => $tl->id, 'quantity' => 500, 'unit_price' => 10]]))
            ->assertSessionHas('error')->assertSessionMissing('stock_warning');
        $this->assertStringContainsString('müşteri (cari)', session('error'));

        // currency change + shortage -> the currency message, not a stock warning
        $this->update($sale, $this->creditPayload($customer, [['product_id' => $usd->id, 'quantity' => 50, 'unit_price' => 10]]))
            ->assertSessionHas('error')->assertSessionMissing('stock_warning');
        $this->assertStringContainsString('para birimi', session('error'));
    }

    public function test_customer_and_currency_can_still_change_when_there_is_no_later_collection(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $other = Account::factory()->create(['type' => 'customer']);
        $tl = $this->product(100, 10);
        $usd = Product::factory()->create(['current_stock' => 100, 'sale_price' => 10, 'currency' => 'USD']);
        $sale = $this->credit($customer, $tl, 10, 10);

        $this->update($sale, $this->creditPayload($other, [['product_id' => $tl->id, 'quantity' => 10, 'unit_price' => 10]]))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame($other->id, $sale->fresh()->account_id);
        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(100.0, $other->fresh()->balance());

        $this->update($sale, $this->creditPayload($other, [['product_id' => $usd->id, 'quantity' => 10, 'unit_price' => 10]]))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('USD', $sale->fresh()->currency);
        $this->assertSame(0.0, $other->fresh()->balanceForCurrency('TL'));
        $this->assertSame(100.0, $other->fresh()->balanceForCurrency('USD'));
        $this->assertSame(100.0, (float) $tl->fresh()->current_stock);
        $this->assertSame(90.0, (float) $usd->fresh()->current_stock);
    }

    public function test_the_change_is_allowed_again_after_the_later_collection_is_cancelled(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $other = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 10, 10);
        $this->collect($customer, $sale);

        $collection = CashTransaction::where('type', 'collection')->whereNull('reversal_of_id')->whereNull('cancelled_at')->first();
        $this->actingAs($this->admin)->post("/cash-transactions/{$collection->id}/cancel")->assertRedirect();
        $this->assertSame(0.0, (float) $sale->fresh()->paid_amount);

        $this->update($sale, $this->creditPayload($other, [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]]))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame($other->id, $sale->fresh()->account_id);
        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(100.0, $other->fresh()->balance());
    }

    public function test_editing_within_the_same_customer_and_currency_still_preserves_the_later_collection(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(100, 10);
        $sale = $this->credit($customer, $product, 10, 10);
        $this->collect($customer, $sale);

        $this->update($sale, $this->creditPayload($customer, [['product_id' => $product->id, 'quantity' => 15, 'unit_price' => 10]]))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');

        $sale->refresh();
        $this->assertSame(150.0, (float) $sale->total);
        $this->assertSame(40.0, (float) $sale->paid_amount);
        $this->assertSame(110.0, $customer->fresh()->balance());
    }
}
