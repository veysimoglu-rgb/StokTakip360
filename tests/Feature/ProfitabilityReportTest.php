<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfitabilityReportTest extends TestCase
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

    private function product(string $currency = 'TL', float $purchasePrice = 40, float $salePrice = 100, int $stock = 100): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
            'currency' => $currency,
        ]);
    }

    private function sell(User $admin, Product $product, int $quantity, float $unitPrice, ?string $saleDate = null): void
    {
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'sale_date' => $saleDate,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ]);
    }

    // =========================== A) ROUTE / ERİŞİM ===========================

    public function test_authenticated_user_can_open_the_profitability_report(): void
    {
        $this->actingAs($this->admin())->get('/reports/profitability')->assertOk();
    }

    public function test_guest_is_redirected_from_the_profitability_report(): void
    {
        $this->get('/reports/profitability')->assertRedirect('/login');
    }

    public function test_personel_can_view_the_profitability_report_like_other_reports(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $personel = User::factory()->create();
        $personel->assignRole('Personel');

        $this->actingAs($personel)->get('/reports/profitability')->assertOk();
    }

    // =========================== B) CURRENCY ===========================

    public function test_tl_usd_eur_are_each_calculated_separately(): void
    {
        $admin = $this->admin();
        $this->sell($admin, $this->product('TL', purchasePrice: 70, salePrice: 100), 10, 100);
        $this->sell($admin, $this->product('USD', purchasePrice: 14, salePrice: 20), 100, 20);
        $this->sell($admin, $this->product('EUR', purchasePrice: 21, salePrice: 30), 100, 30);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');

        $summary = $response->viewData('summaryByCurrency');

        $this->assertSame(1000.0, (float) $summary['TL']->revenue);
        $this->assertSame(300.0, (float) $summary['TL']->profit);

        $this->assertSame(2000.0, (float) $summary['USD']->revenue);
        $this->assertSame(600.0, (float) $summary['USD']->profit);

        $this->assertSame(3000.0, (float) $summary['EUR']->revenue);
        $this->assertSame(900.0, (float) $summary['EUR']->profit);
    }

    public function test_no_currency_ever_blends_into_another_in_the_response(): void
    {
        $admin = $this->admin();
        $this->sell($admin, $this->product('TL', purchasePrice: 100, salePrice: 1000), 1, 1000);
        $this->sell($admin, $this->product('USD', purchasePrice: 700, salePrice: 2000), 1, 2000);

        $content = $this->actingAs($admin)->get('/reports/profitability?period=this_month')->getContent();

        // 1000 TL + 2000 USD must never be blended into "3.000".
        $this->assertStringNotContainsString('3.000,00', $content);
    }

    // =========================== C) HESAP ===========================

    public function test_revenue_cost_profit_and_margin_are_computed_correctly(): void
    {
        $admin = $this->admin();
        $product = $this->product('TL', purchasePrice: 70, salePrice: 100);
        $this->sell($admin, $product, 10, 100);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');
        $row = $response->viewData('summaryByCurrency')['TL'];

        $this->assertSame(1000.0, (float) $row->revenue);
        $this->assertSame(700.0, (float) $row->cost);
        $this->assertSame(300.0, (float) $row->profit);
        $this->assertSame(30.0, round((float) $row->margin, 2));
    }

    public function test_margin_does_not_divide_by_zero_when_there_is_no_cost_known_revenue(): void
    {
        $admin = $this->admin();
        $product = $this->product('TL');
        $sale = Sale::create([
            'number' => 'SAT-NOCOST', 'payment_type' => 'pesin', 'subtotal' => 100,
            'discount_total' => 0, 'total' => 100, 'paid_amount' => 100, 'currency' => 'TL',
            'status' => 'paid', 'sale_date' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => 100, 'line_total' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');
        $row = $response->viewData('summaryByCurrency')['TL'];

        $this->assertNull($row->profit);
        $this->assertNull($row->margin);
        $response->assertOk();
    }

    // =========================== D) NULL COST ===========================

    public function test_null_cost_price_sale_is_excluded_from_profit_but_not_from_revenue(): void
    {
        $admin = $this->admin();
        $costedProduct = $this->product('TL', purchasePrice: 60, salePrice: 100);
        $this->sell($admin, $costedProduct, 4, 100); // 400 revenue, 240 cost, 160 profit

        $legacyProduct = $this->product('TL');
        $sale = Sale::create([
            'number' => 'SAT-LEGACY', 'payment_type' => 'pesin', 'subtotal' => 100,
            'discount_total' => 0, 'total' => 100, 'paid_amount' => 100, 'currency' => 'TL',
            'status' => 'paid', 'sale_date' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $sale->id, 'product_id' => $legacyProduct->id, 'quantity' => 1,
            'unit_price' => 100, 'line_total' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');
        $row = $response->viewData('summaryByCurrency')['TL'];

        // Full revenue (400 + 100) is never lost...
        $this->assertSame(500.0, (float) $row->revenue);
        // ...but cost/profit only reflect the costed line.
        $this->assertSame(240.0, (float) $row->cost);
        $this->assertSame(160.0, (float) $row->profit);
        $this->assertSame(100.0, (float) $row->revenue_without_cost);
        $this->assertSame(1, (int) $row->qty_without_cost);
    }

    public function test_report_shows_missing_cost_info_message_not_a_zero(): void
    {
        $admin = $this->admin();
        $product = $this->product('TL');
        $sale = Sale::create([
            'number' => 'SAT-LEGACY-2', 'payment_type' => 'pesin', 'subtotal' => 100,
            'discount_total' => 0, 'total' => 100, 'paid_amount' => 100, 'currency' => 'TL',
            'status' => 'paid', 'sale_date' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => 100, 'line_total' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');

        $response->assertOk();
        $response->assertSee('Maliyet bilgisi yok');
    }

    // =========================== E) CANCELLATION ===========================

    public function test_cancelled_sale_is_excluded_from_the_profitability_report(): void
    {
        $admin = $this->admin();
        $product = $this->product('TL', purchasePrice: 40, salePrice: 100);
        $this->sell($admin, $product, 5, 100);
        $sale = Sale::first();
        $this->actingAs($admin)->post("/sales/{$sale->id}/cancel");

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');

        $this->assertTrue($response->viewData('summaryByCurrency')->isEmpty());
    }

    // =========================== F) FİLTRE ===========================

    public function test_date_range_filter_excludes_sales_outside_the_range(): void
    {
        $admin = $this->admin();
        $inside = $this->product('TL', purchasePrice: 40, salePrice: 100);
        $outside = $this->product('TL', purchasePrice: 40, salePrice: 100);

        $this->sell($admin, $inside, 1, 100, '2025-06-15');
        $this->sell($admin, $outside, 1, 100, '2025-01-01');

        $response = $this->actingAs($admin)->get('/reports/profitability?date_from=2025-06-01&date_to=2025-06-30');

        $row = $response->viewData('summaryByCurrency')['TL'];
        $this->assertSame(100.0, (float) $row->revenue);
    }

    public function test_currency_filter_returns_only_the_selected_currency(): void
    {
        $admin = $this->admin();
        $this->sell($admin, $this->product('TL', purchasePrice: 40, salePrice: 100), 1, 100);
        $this->sell($admin, $this->product('USD', purchasePrice: 14, salePrice: 20), 1, 20);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month&currency=USD');

        $summary = $response->viewData('summaryByCurrency');
        $this->assertCount(1, $summary);
        $this->assertArrayHasKey('USD', $summary->toArray());
    }

    public function test_product_filter_narrows_summary_and_product_table(): void
    {
        $admin = $this->admin();
        $productA = $this->product('TL', purchasePrice: 40, salePrice: 100);
        $productB = $this->product('TL', purchasePrice: 20, salePrice: 50);
        $this->sell($admin, $productA, 1, 100);
        $this->sell($admin, $productB, 1, 50);

        $response = $this->actingAs($admin)->get("/reports/profitability?period=this_month&product_id={$productA->id}");

        $row = $response->viewData('summaryByCurrency')['TL'];
        $this->assertSame(100.0, (float) $row->revenue);

        $productRows = $response->viewData('productPerformanceByCurrency')['TL'];
        $this->assertCount(1, $productRows);
        $this->assertSame($productA->id, $productRows->first()->product_id);
    }

    public function test_category_filter_narrows_the_report(): void
    {
        $admin = $this->admin();
        $categoryA = Category::create(['name' => 'A', 'active' => true]);
        $categoryB = Category::create(['name' => 'B', 'active' => true]);

        $productA = Product::factory()->create(['category_id' => $categoryA->id, 'currency' => 'TL', 'purchase_price' => 40, 'sale_price' => 100, 'current_stock' => 50]);
        $productB = Product::factory()->create(['category_id' => $categoryB->id, 'currency' => 'TL', 'purchase_price' => 20, 'sale_price' => 50, 'current_stock' => 50]);

        $this->sell($admin, $productA, 1, 100);
        $this->sell($admin, $productB, 1, 50);

        $response = $this->actingAs($admin)->get("/reports/profitability?period=this_month&category_id={$categoryA->id}");

        $row = $response->viewData('summaryByCurrency')['TL'];
        $this->assertSame(100.0, (float) $row->revenue);
    }

    public function test_combined_filters_work_together(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Combo', 'active' => true]);
        $matching = Product::factory()->create(['category_id' => $category->id, 'currency' => 'USD', 'purchase_price' => 10, 'sale_price' => 20, 'current_stock' => 50]);
        $wrongCurrency = Product::factory()->create(['category_id' => $category->id, 'currency' => 'TL', 'purchase_price' => 10, 'sale_price' => 20, 'current_stock' => 50]);

        $this->sell($admin, $matching, 1, 20);
        $this->sell($admin, $wrongCurrency, 1, 20);

        $response = $this->actingAs($admin)->get("/reports/profitability?period=this_month&currency=USD&category_id={$category->id}");

        $summary = $response->viewData('summaryByCurrency');
        $this->assertCount(1, $summary);
        $this->assertSame(20.0, (float) $summary['USD']->revenue);
    }

    // =========================== G) ÜRÜN PERFORMANSI ===========================

    public function test_product_performance_table_computes_qty_revenue_cost_profit_margin(): void
    {
        $admin = $this->admin();
        $product = $this->product('TL', purchasePrice: 60, salePrice: 100);
        $this->sell($admin, $product, 3, 100);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');
        $row = $response->viewData('productPerformanceByCurrency')['TL']->first();

        $this->assertSame($product->id, $row->product_id);
        $this->assertSame(3, (int) $row->qty);
        $this->assertSame(300.0, (float) $row->revenue);
        $this->assertSame(180.0, (float) $row->cost);
        $this->assertSame(120.0, (float) $row->profit);
        $this->assertSame(40.0, round((float) $row->margin, 2));
    }

    // =========================== H) MULTI-CURRENCY ===========================

    public function test_same_product_history_in_two_currencies_produces_two_separate_rows(): void
    {
        $admin = $this->admin();
        $product = $this->product('TL', purchasePrice: 40, salePrice: 100);
        $this->sell($admin, $product, 1, 100);

        // Simulate the product having been sold under a different currency
        // in the past (e.g. its currency was changed later by an admin) —
        // a raw historical sale_item row tagged with a USD sale.
        $usdSale = Sale::create([
            'number' => 'SAT-USD-HIST', 'payment_type' => 'pesin', 'subtotal' => 50,
            'discount_total' => 0, 'total' => 50, 'paid_amount' => 50, 'currency' => 'USD',
            'status' => 'paid', 'sale_date' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $usdSale->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => 50, 'cost_price' => 30, 'line_total' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/reports/profitability?period=this_month');
        $byCurrency = $response->viewData('productPerformanceByCurrency');

        $this->assertSame(100.0, (float) $byCurrency['TL']->first()->revenue);
        $this->assertSame(50.0, (float) $byCurrency['USD']->first()->revenue);
        $this->assertSame(20.0, (float) $byCurrency['USD']->first()->profit);
    }

    // =========================== I) CSV / PRINT ===========================

    public function test_csv_export_keeps_currency_columns_separate(): void
    {
        $admin = $this->admin();
        $this->sell($admin, $this->product('TL', purchasePrice: 40, salePrice: 100), 1, 100);
        $this->sell($admin, $this->product('USD', purchasePrice: 14, salePrice: 20), 1, 20);

        $response = $this->actingAs($admin)->get('/reports/profitability/export?period=this_month');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Para Birimi', $content);
        $this->assertStringContainsString('TL', $content);
        $this->assertStringContainsString('USD', $content);
        $this->assertStringNotContainsString('120,00', $content); // no blended 100+20
    }

    public function test_print_view_keeps_currencies_in_separate_blocks(): void
    {
        $admin = $this->admin();
        $this->sell($admin, $this->product('TL', purchasePrice: 40, salePrice: 100), 1, 100);
        $this->sell($admin, $this->product('EUR', purchasePrice: 21, salePrice: 30), 1, 30);

        $response = $this->actingAs($admin)->get('/reports/profitability/print?period=this_month');

        $response->assertOk();
        $response->assertSee('TL', false);
        $response->assertSee('EUR', false);
    }

    // =========================== J) REGRESSION ===========================

    public function test_reports_index_lists_the_profitability_report(): void
    {
        $response = $this->actingAs($this->admin())->get('/reports');

        $response->assertOk();
        $response->assertSee('Kârlılık Raporu');
    }
}
