<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPackagingTest extends TestCase
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

    private function balya(array $overrides = []): Product
    {
        return Product::factory()->create($overrides + [
            'current_stock' => 100000, 'sale_price' => 2, 'currency' => 'TL', 'unit' => 'Adet',
            'package_label' => 'Balya', 'package_qty' => 15, 'subunit_label' => 'Paket',
            'subunit_to_base_qty' => 100, 'package_weight_kg' => 8.5,
        ]);
    }

    private function productPayload(array $extra = []): array
    {
        return $extra + [
            'code' => 'BLY-1', 'name' => 'Balya ürün', 'unit' => 'Adet', 'min_stock' => 0,
            'purchase_price' => 1, 'sale_price' => 2, 'currency' => 'TL', 'vat_rate' => 20,
        ];
    }

    // 1 balya = 15 paket, 1 paket = 100 adet, 10 balya = 15.000 adet, 85 kg
    public function test_ten_balya_becomes_fifteen_thousand_units_of_stock_and_eighty_five_kg(): void
    {
        $product = $this->balya();

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 15000, 'package_qty_input' => 10, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $sale = Sale::first();
        $item = $sale->items()->first();

        $this->assertSame(15000.0, (float) $item->quantity);
        $this->assertSame(10.0, (float) $item->package_qty_input);
        $this->assertSame(1500, (int) $item->unit_multiplier_snapshot);
        $this->assertSame(85.0, (float) $item->line_weight_kg);
        $this->assertSame(85.0, $sale->fresh()->load('items')->totalWeightKg());
        $this->assertSame(30000.0, (float) $sale->total);

        $this->assertSame(15000.0, (float) StockMovement::first()->quantity);
        $this->assertSame(85000.0, (float) $product->fresh()->current_stock);
    }

    // Sunucu tek yetkilidir: tarayıcı yanlış/eski adet gönderse de balya sayısından hesaplanır
    public function test_the_server_recomputes_units_from_the_package_count(): void
    {
        $product = $this->balya();

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 2, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3000.0, (float) Sale::first()->items()->first()->quantity);
        $this->assertSame(97000.0, (float) $product->fresh()->current_stock);
    }

    public function test_fractional_package_counts_are_supported(): void
    {
        $product = $this->balya();

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 0.5, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $item = Sale::first()->items()->first();
        $this->assertSame(750.0, (float) $item->quantity);
        $this->assertSame(4.25, (float) $item->line_weight_kg);
    }

    public function test_products_without_packaging_keep_the_plain_quantity_flow(): void
    {
        $product = Product::factory()->create(['current_stock' => 50, 'sale_price' => 10, 'currency' => 'TL']);

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 7, 'package_qty_input' => 3, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();

        $item = Sale::first()->items()->first();
        $this->assertSame(7.0, (float) $item->quantity);
        $this->assertNull($item->package_qty_input);
        $this->assertNull($item->unit_multiplier_snapshot);
        $this->assertNull($item->line_weight_kg);
        $this->assertSame(43.0, (float) $product->fresh()->current_stock);
    }

    public function test_a_packaged_product_without_a_weight_has_no_line_weight(): void
    {
        $product = $this->balya(['package_weight_kg' => null]);

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 1, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Sale::first()->items()->first()->line_weight_kg);
    }

    // Ürün kartı sonradan değişse de geçmiş sipariş bozulmaz
    public function test_changing_the_product_packaging_later_does_not_alter_past_orders(): void
    {
        $product = $this->balya();
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 10, 'unit_price' => 2]],
        ]);

        $product->update(['package_qty' => 20, 'subunit_to_base_qty' => 50, 'package_weight_kg' => 9]);

        $item = Sale::first()->items()->first();
        $this->assertSame(15000.0, (float) $item->quantity);
        $this->assertSame(1500, (int) $item->unit_multiplier_snapshot);
        $this->assertSame(85.0, (float) $item->line_weight_kg);
    }

    public function test_a_packaged_line_shortfall_is_measured_in_base_units(): void
    {
        $product = $this->balya(['current_stock' => 10000]);

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 10, 'unit_price' => 2]],
        ])->assertSessionHas('stock_warning');

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin', 'confirm_insufficient_stock' => 1,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 10, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $item = Sale::first()->items()->first();
        $this->assertSame(15000.0, (float) $item->quantity);
        $this->assertSame(5000.0, (float) $item->stock_shortfall_quantity);
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }

    public function test_packaging_fields_can_be_saved_on_the_product_card(): void
    {
        $this->actingAs($this->admin)->post('/products', $this->productPayload([
            'package_label' => 'Balya', 'package_qty' => 15, 'subunit_label' => 'Paket',
            'subunit_to_base_qty' => 100, 'package_weight_kg' => 8.5,
        ]))->assertSessionHasNoErrors();

        $product = Product::where('code', 'BLY-1')->first();
        $this->assertTrue($product->hasPackaging());
        $this->assertSame(1500, $product->baseUnitsPerPackage());
        $this->assertSame(8.5, (float) $product->package_weight_kg);
    }

    public function test_packaging_is_optional_and_both_multipliers_must_come_together(): void
    {
        $this->actingAs($this->admin)->post('/products', $this->productPayload(['code' => 'PLAIN-1']))->assertSessionHasNoErrors();
        $this->assertFalse(Product::where('code', 'PLAIN-1')->first()->hasPackaging());
        $this->assertSame(0, Product::where('code', 'PLAIN-1')->first()->baseUnitsPerPackage());

        $this->actingAs($this->admin)->post('/products', $this->productPayload(['code' => 'HALF-1', 'package_qty' => 15]))
            ->assertSessionHasErrors('subunit_to_base_qty');
        $this->actingAs($this->admin)->post('/products', $this->productPayload(['code' => 'HALF-2', 'subunit_to_base_qty' => 100]))
            ->assertSessionHasErrors('package_qty');
        $this->actingAs($this->admin)->post('/products', $this->productPayload(['code' => 'BAD-1', 'package_qty' => 0, 'subunit_to_base_qty' => 100]))
            ->assertSessionHasErrors('package_qty');
        $this->actingAs($this->admin)->post('/products', $this->productPayload(['code' => 'BAD-2', 'package_weight_kg' => 1.2345]))
            ->assertSessionHasErrors('package_weight_kg');
    }

    public function test_the_product_pages_and_order_form_render_with_packaging(): void
    {
        $product = $this->balya(['name' => 'Naylon Poşet']);

        $this->actingAs($this->admin)->get("/products/{$product->id}")->assertOk()->assertSee('1 Balya = 15 Paket = 1.500 Adet');
        $this->actingAs($this->admin)->get("/products/{$product->id}/edit")->assertOk()->assertSee('Paketleme (opsiyonel)');
        $this->actingAs($this->admin)->get('/products/create')->assertOk()->assertSee('Paketleme (opsiyonel)');
        $this->actingAs($this->admin)->get('/sales/create')->assertOk()->assertSee('Naylon Poşet')->assertSee('Balya');
    }

    public function test_orders_with_packaging_still_hit_the_cari_with_the_full_amount(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->balya();

        $this->actingAs($this->admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 10, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(30000.0, $customer->fresh()->balance());
    }
}
