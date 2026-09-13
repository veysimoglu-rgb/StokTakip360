<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MovementValueReportFiltersTest extends TestCase
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

    private function personel(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $user = User::factory()->create();
        $user->assignRole('Personel');

        return $user;
    }

    public function test_default_period_is_this_month(): void
    {
        $product = Product::factory()->create(['currency' => 'TL', 'name' => 'InThisMonth']);
        $oldProduct = Product::factory()->create(['currency' => 'TL', 'name' => 'FromTwoMonthsAgo']);

        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 5,
            'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now(),
        ]);
        StockMovement::create([
            'product_id' => $oldProduct->id, 'type' => 'in', 'quantity' => 5,
            'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now()->subMonths(2),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value');

        $response->assertOk();
        $this->assertSame('this_month', $response->viewData('period'));
        $names = $response->viewData('movements')->pluck('product.name');
        $this->assertTrue($names->contains('InThisMonth'));
        $this->assertFalse($names->contains('FromTwoMonthsAgo'));
    }

    public function test_date_range_filter_narrows_results(): void
    {
        $product = Product::factory()->create(['name' => 'InsideRange']);
        $outsideProduct = Product::factory()->create(['name' => 'OutsideRange']);

        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 1,
            'unit_price' => 10, 'currency' => 'TL', 'movement_date' => '2025-06-15',
        ]);
        StockMovement::create([
            'product_id' => $outsideProduct->id, 'type' => 'in', 'quantity' => 1,
            'unit_price' => 10, 'currency' => 'TL', 'movement_date' => '2025-01-01',
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value?date_from=2025-06-01&date_to=2025-06-30');

        $response->assertOk();
        $names = $response->viewData('movements')->pluck('product.name');
        $this->assertTrue($names->contains('InsideRange'));
        $this->assertFalse($names->contains('OutsideRange'));
    }

    public function test_type_filter_isolates_in_or_out(): void
    {
        $product = Product::factory()->create(['currency' => 'TL', 'current_stock' => 100]);

        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 20,
            'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now(),
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'out', 'quantity' => 7,
            'unit_price' => 15, 'currency' => 'TL', 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value?type=in&period=this_month');

        $response->assertOk();
        $this->assertSame(20, $response->viewData('qtyTotals')->get('in'));
        $this->assertNull($response->viewData('qtyTotals')->get('out'));
    }

    public function test_currency_filter_isolates_one_currency(): void
    {
        $tlProduct = Product::factory()->create(['currency' => 'TL']);
        $usdProduct = Product::factory()->create(['currency' => 'USD']);

        StockMovement::create([
            'product_id' => $tlProduct->id, 'type' => 'in', 'quantity' => 10,
            'unit_price' => 50, 'currency' => 'TL', 'movement_date' => now(),
        ]);
        StockMovement::create([
            'product_id' => $usdProduct->id, 'type' => 'in', 'quantity' => 10,
            'unit_price' => 100, 'currency' => 'USD', 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value?currency=TL&period=this_month');

        $totals = $response->viewData('totalsByCurrency');
        $this->assertTrue($totals->has('TL'));
        $this->assertFalse($totals->has('USD'));
    }

    public function test_product_and_category_filters(): void
    {
        $catA = Category::create(['name' => 'Kategori A']);
        $catB = Category::create(['name' => 'Kategori B']);
        $productA = Product::factory()->create(['category_id' => $catA->id, 'name' => 'ProductInCatA']);
        $productB = Product::factory()->create(['category_id' => $catB->id, 'name' => 'ProductInCatB']);

        foreach ([$productA, $productB] as $p) {
            StockMovement::create([
                'product_id' => $p->id, 'type' => 'in', 'quantity' => 1,
                'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now(),
            ]);
        }

        $byProduct = $this->actingAs($this->admin())->get("/reports/movement-value?product_id={$productA->id}&period=this_month");
        $byProductNames = $byProduct->viewData('movements')->pluck('product.name');
        $this->assertTrue($byProductNames->contains('ProductInCatA'));
        $this->assertFalse($byProductNames->contains('ProductInCatB'));

        $byCategory = $this->actingAs($this->admin())->get("/reports/movement-value?category_id={$catB->id}&period=this_month");
        $byCategoryNames = $byCategory->viewData('movements')->pluck('product.name');
        $this->assertTrue($byCategoryNames->contains('ProductInCatB'));
        $this->assertFalse($byCategoryNames->contains('ProductInCatA'));
    }

    public function test_account_filter(): void
    {
        $customerAccount = Account::factory()->create(['type' => 'customer', 'name' => 'Test Müşteri']);
        $supplierAccount = Account::factory()->create(['type' => 'supplier', 'name' => 'Test Tedarikçi']);
        $product = Product::factory()->create();

        StockMovement::create([
            'product_id' => $product->id, 'type' => 'out', 'quantity' => 2,
            'unit_price' => 20, 'currency' => 'TL', 'account_id' => $customerAccount->id, 'movement_date' => now(),
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 3,
            'unit_price' => 15, 'currency' => 'TL', 'account_id' => $supplierAccount->id, 'movement_date' => now(),
        ]);

        $byCustomer = $this->actingAs($this->admin())->get("/reports/movement-value?account_id={$customerAccount->id}&period=this_month");
        $this->assertSame(2, $byCustomer->viewData('qtyTotals')->get('out'));
        $this->assertNull($byCustomer->viewData('qtyTotals')->get('in'));

        $bySupplier = $this->actingAs($this->admin())->get("/reports/movement-value?account_id={$supplierAccount->id}&period=this_month");
        $this->assertSame(3, $bySupplier->viewData('qtyTotals')->get('in'));
        $this->assertNull($bySupplier->viewData('qtyTotals')->get('out'));
    }

    public function test_currencies_are_never_summed_together(): void
    {
        $tlProduct = Product::factory()->create(['currency' => 'TL']);
        $usdProduct = Product::factory()->create(['currency' => 'USD']);
        $eurProduct = Product::factory()->create(['currency' => 'EUR']);

        StockMovement::create(['product_id' => $tlProduct->id, 'type' => 'out', 'quantity' => 5, 'unit_price' => 100, 'currency' => 'TL', 'movement_date' => now()]);
        StockMovement::create(['product_id' => $usdProduct->id, 'type' => 'out', 'quantity' => 2, 'unit_price' => 100, 'currency' => 'USD', 'movement_date' => now()]);
        StockMovement::create(['product_id' => $eurProduct->id, 'type' => 'out', 'quantity' => 1, 'unit_price' => 100, 'currency' => 'EUR', 'movement_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value?period=this_month');
        $totals = $response->viewData('totalsByCurrency');

        $this->assertEquals(500, $totals->get('TL')->firstWhere('type', 'out')->total);
        $this->assertEquals(200, $totals->get('USD')->firstWhere('type', 'out')->total);
        $this->assertEquals(100, $totals->get('EUR')->firstWhere('type', 'out')->total);
    }

    public function test_product_summary_groups_out_movements_by_product(): void
    {
        $product = Product::factory()->create(['currency' => 'TL', 'name' => 'Çok Satan', 'code' => 'COK-001']);

        StockMovement::create(['product_id' => $product->id, 'type' => 'out', 'quantity' => 3, 'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now()]);
        StockMovement::create(['product_id' => $product->id, 'type' => 'out', 'quantity' => 7, 'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value?period=this_month');
        $summary = $response->viewData('productSummary');
        $row = $summary->firstWhere('code', 'COK-001');

        $this->assertNotNull($row);
        $this->assertEquals(10, $row->qty_out);
        $this->assertEquals(100, $row->amount);
    }

    public function test_export_returns_csv_with_filtered_rows(): void
    {
        $product = Product::factory()->create(['name' => 'ExportUrunu', 'code' => 'EXP-001', 'currency' => 'TL']);
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'out', 'quantity' => 4,
            'unit_price' => 25, 'currency' => 'TL', 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value/export?period=this_month');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('EXP-001', $content);
        $this->assertStringContainsString('100,00', $content); // 4 x 25
    }

    public function test_print_view_renders_with_legacy_and_current_movements(): void
    {
        $product = Product::factory()->create(['currency' => 'TL']);
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 5,
            'unit_price' => 40, 'currency' => null, 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value/print?period=this_month');

        $response->assertOk();
        $response->assertSee('Stok Hareketi Değer Raporu');
        $response->assertSee('para birimi bilinmiyor');
    }

    public function test_personel_can_view_report_export_and_print(): void
    {
        $personel = $this->personel();

        $this->actingAs($personel)->get('/reports/movement-value')->assertOk();
        $this->actingAs($personel)->get('/reports/movement-value/export')->assertOk();
        $this->actingAs($personel)->get('/reports/movement-value/print')->assertOk();
    }

    public function test_existing_stock_movements_screen_still_works(): void
    {
        $product = Product::factory()->create();
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 5,
            'unit_price' => 10, 'currency' => 'TL', 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/stock-movements');

        $response->assertOk();
        $response->assertSee($product->name);
    }

    public function test_dashboard_still_works_after_report_changes(): void
    {
        Product::factory()->create(['currency' => 'TL', 'active' => true]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
    }
}
