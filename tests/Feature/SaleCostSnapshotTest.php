<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    private function product(string $currency, float $purchasePrice, int $stock = 50, float $salePrice = 100): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
            'currency' => $currency,
        ]);
    }

    // 1. Yeni satışta cost_price, Product.purchase_price'dan snapshot alınıyor.
    public function test_new_sale_snapshots_cost_price_from_product_purchase_price(): void
    {
        $product = $this->product('TL', purchasePrice: 60, salePrice: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])->assertRedirect();

        $saleItem = SaleItem::first();
        $this->assertNotNull($saleItem);
        $this->assertSame(60.0, (float) $saleItem->cost_price);
    }

    // 2 / 8 (kritik test). Product.purchase_price satıştan sonra değişse bile
    // geçmiş satışın cost_price snapshot'ı değişmiyor.
    public function test_changing_purchase_price_after_sale_does_not_affect_existing_cost_price(): void
    {
        $product = $this->product('USD', purchasePrice: 70, salePrice: 120);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 120]],
        ]);

        $saleItem = SaleItem::first();
        $this->assertSame(70.0, (float) $saleItem->cost_price);

        // Ürünün maliyeti güncellenir...
        $product->update(['purchase_price' => 90]);

        // ...ama geçmiş satırın snapshot'ı hâlâ eski (70 USD) değerinde kalmalı.
        $this->assertSame(70.0, (float) $saleItem->fresh()->cost_price);
        $this->assertSame(90.0, (float) $product->fresh()->purchase_price);
    }

    // 3. TL satışta cost_price TL değerinde doğru.
    public function test_tl_sale_cost_price_is_correct(): void
    {
        $product = $this->product('TL', purchasePrice: 45.5, salePrice: 80);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80]],
        ]);

        $sale = Sale::first();
        $this->assertSame('TL', $sale->currency);
        $this->assertSame(45.5, (float) SaleItem::first()->cost_price);
    }

    // 4. USD satışta cost_price USD değerinde doğru.
    public function test_usd_sale_cost_price_is_correct(): void
    {
        $product = $this->product('USD', purchasePrice: 55.25, salePrice: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $sale = Sale::first();
        $this->assertSame('USD', $sale->currency);
        $this->assertSame(55.25, (float) SaleItem::first()->cost_price);
    }

    // 5. EUR satışta cost_price EUR değerinde doğru.
    public function test_eur_sale_cost_price_is_correct(): void
    {
        $product = $this->product('EUR', purchasePrice: 38.0, salePrice: 65);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 65]],
        ]);

        $sale = Sale::first();
        $this->assertSame('EUR', $sale->currency);
        $this->assertSame(38.0, (float) SaleItem::first()->cost_price);
    }

    // 6. Birden fazla ürünlü satışta her satır kendi ürününün purchase_price'ını snapshot alıyor.
    public function test_multi_item_sale_each_line_snapshots_its_own_products_purchase_price(): void
    {
        $productA = $this->product('TL', purchasePrice: 40, salePrice: 70);
        $productB = $this->product('TL', purchasePrice: 25, salePrice: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2, 'unit_price' => 70],
                ['product_id' => $productB->id, 'quantity' => 3, 'unit_price' => 50],
            ],
        ]);

        $itemA = SaleItem::where('product_id', $productA->id)->first();
        $itemB = SaleItem::where('product_id', $productB->id)->first();

        $this->assertSame(40.0, (float) $itemA->cost_price);
        $this->assertSame(25.0, (float) $itemB->cost_price);
    }

    // 7. İptal edilen satışın cost_price snapshot'ı değişmiyor.
    public function test_cancelling_a_sale_does_not_change_cost_price_snapshot(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('TL', purchasePrice: 33, salePrice: 60);

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 60]],
        ]);

        $sale = Sale::first();
        $saleItem = SaleItem::first();

        $this->actingAs($admin)->post("/sales/{$sale->id}/cancel")->assertRedirect();

        $this->assertTrue($sale->fresh()->isCancelled());
        $this->assertSame(33.0, (float) $saleItem->fresh()->cost_price);
    }

    // 8. Mevcut eski sale_items.cost_price NULL kalıyor (migration backfill yapılmadı).
    public function test_existing_sale_items_have_null_cost_price(): void
    {
        $product = $this->product('TL', purchasePrice: 50, salePrice: 90);
        $sale = Sale::create([
            'number' => 'SAT-LEGACY',
            'payment_type' => 'pesin',
            'subtotal' => 90,
            'discount_total' => 0,
            'total' => 90,
            'paid_amount' => 90,
            'currency' => 'TL',
            'status' => 'paid',
            'sale_date' => now(),
        ]);

        // Doğrudan DB::table ile, cost_price sütununu hiç göndermeden — WP-9
        // öncesi bir satırı simüle ediyor.
        DB::table('sale_items')->insert([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 90,
            'line_total' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyItem = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertNull($legacyItem->cost_price);
    }

    // 9. Geçersiz satış rollback olduğunda cost_price içeren sale_item kaydı da oluşmuyor.
    public function test_invalid_sale_creates_no_sale_items_with_cost_price(): void
    {
        $product = $this->product('TL', purchasePrice: 20, stock: 2, salePrice: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 50]],
        ])->assertSessionHas('error');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SaleItem::count());
    }

    // 10. Regresyon: mevcut Sale davranışı (toplam, ödeme durumu, stok, cari, kasa) bozulmadı.
    public function test_sale_totals_status_stock_and_ledgers_are_unaffected_by_cost_snapshot(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('TL', purchasePrice: 30, stock: 50, salePrice: 100);

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $sale = Sale::first();
        $this->assertSame(1000.0, (float) $sale->total);
        $this->assertSame('partial', $sale->status);
        $this->assertSame(40, $product->fresh()->current_stock);
        $this->assertSame(600.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(400.0, CashTransaction::balanceForCurrency('TL'));
    }
}
