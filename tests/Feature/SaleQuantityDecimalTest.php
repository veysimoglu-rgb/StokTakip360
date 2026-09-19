<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleQuantityDecimalTest extends TestCase
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

    private function order(Product $product, $quantity, float $price = 100, array $extra = [])
    {
        return $this->actingAs($this->admin)->post('/sales', $extra + [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ]);
    }

    public function test_a_three_decimal_quantity_is_accepted_and_stored_exactly(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'currency' => 'TL']);

        $this->order($product, 0.345)->assertSessionHasNoErrors();

        $sale = Sale::first();
        $this->assertSame('0.345', (string) $sale->items()->first()->quantity);
        $this->assertSame(34.5, (float) $sale->total);
        $this->assertSame(0.345, (float) StockMovement::first()->quantity);
        $this->assertEqualsWithDelta(9.655, (float) $product->fresh()->current_stock, 0.00001);
        $this->assertSame(34.5, CashTransaction::balance());
    }

    public function test_a_fourth_decimal_is_rejected_by_validation(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'currency' => 'TL']);

        $this->order($product, 0.3456)->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(0, Sale::count());
        $this->assertSame(10.0, (float) $product->fresh()->current_stock);
    }

    public function test_zero_negative_and_comma_formatted_quantities_are_rejected(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'currency' => 'TL']);

        foreach ([0, -1, '0,345', '1.000,5', 'abc'] as $bad) {
            $this->order($product, $bad)->assertSessionHasErrors('items.0.quantity');
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_large_whole_quantities_are_stored_as_plain_numbers(): void
    {
        $product = Product::factory()->create(['current_stock' => 50000, 'currency' => 'TL']);

        $this->order($product, 20000, 2)->assertSessionHasNoErrors();

        $this->assertSame(20000.0, (float) Sale::first()->items()->first()->quantity);
        $this->assertSame(40000.0, (float) Sale::first()->total);
        $this->assertSame(30000.0, (float) $product->fresh()->current_stock);
    }

    public function test_line_totals_are_rounded_to_two_decimals(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'currency' => 'TL']);

        $this->order($product, 0.333, 10)->assertSessionHasNoErrors(); // 3,33

        $this->assertSame(3.33, (float) Sale::first()->total);
    }

    public function test_decimal_shortage_is_tracked_precisely_and_stock_stays_non_negative(): void
    {
        $product = Product::factory()->create(['current_stock' => 0.5, 'currency' => 'TL']);

        $this->order($product, 0.6)->assertSessionHas('stock_warning');
        $this->assertSame(0, Sale::count());

        $this->order($product, 0.6, 100, ['confirm_insufficient_stock' => 1])->assertSessionHasNoErrors();

        $item = Sale::first()->items()->first();
        $this->assertSame(0.6, (float) $item->quantity);
        $this->assertSame(0.1, (float) $item->stock_shortfall_quantity);
        $this->assertSame(0.5, (float) StockMovement::first()->quantity);
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }

    public function test_product_min_stock_accepts_three_decimals(): void
    {
        $this->actingAs($this->admin)->post('/products', [
            'code' => 'KG-1', 'name' => 'Kg ürün', 'unit' => 'Kg', 'min_stock' => 2.5,
            'purchase_price' => 1, 'sale_price' => 2, 'currency' => 'TL', 'vat_rate' => 20,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2.5, (float) Product::where('code', 'KG-1')->first()->min_stock);
    }

    public function test_order_form_starts_with_an_empty_quantity_and_has_no_implicit_submit(): void
    {
        $content = $this->actingAs($this->admin)->get('/sales/create')->assertOk()->getContent();

        // Boş adet: satır başlangıç durumunda miktar "" (eski varsayılan 1 değil)
        $this->assertStringContainsString('\\u0022quantity\\u0022:\\u0022\\u0022', $content);
        $this->assertStringNotContainsString('quantity: 1,', $content);

        // Enter formu göndermez: formda hiç submit butonu yok, kayıt yalnızca save() ile
        $start = strpos($content, '<form method="POST" action="'.route('sales.store').'"');
        $end = strpos($content, '</form>', $start);
        $form = substr($content, $start, $end - $start);

        $this->assertStringNotContainsString('type="submit"', $form);
        $this->assertStringContainsString('@keydown.enter="onEnter($event)"', $form);
        $this->assertStringContainsString('@click="save(false)"', $form);
        // Miktar Türkçe biçimde (0,345 / 20.000) yazılan metin alanı; backend'e gizli alanla noktalı sayı gider
        $this->assertStringContainsString('x-model="item.quantity_text"', $form);
        $this->assertStringContainsString('inputmode="decimal"', $form);
        $this->assertStringContainsString(":name=\"'items['+index+'][quantity]'\" :value=\"item.quantity\"", $form);
        $this->assertStringNotContainsString('x-model.number="item.quantity"', $form);
        // Müşteri kartı ile Ürünler kartı arasında fazladan boşluk (yalnızca Ürünler kartında)
        $this->assertSame(1, substr_count($form, 'style="margin-top: 2.75rem"'));
        $this->assertStringContainsString('Siparişi Kaydet', $form);

        // Ürün satırları: 900px ve üzerinde kompakt tek satır (Tailwind derlemesinden bağımsız, kendi CSS'i), altında dikey
        $this->assertStringContainsString('@media (min-width: 900px)', $content);
        $this->assertStringContainsString('div.order-row', $content);
        $this->assertStringContainsString('class="order-head', $form);
        $this->assertStringNotContainsString('sm:grid-cols-[2fr_1.3fr_1fr_1fr_auto]', $form);
    }
}
