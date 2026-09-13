<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function product(int $stock = 100, float $price = 50): Product
    {
        return Product::factory()->create(['current_stock' => $stock, 'sale_price' => $price, 'purchase_price' => $price, 'currency' => 'TL']);
    }

    // 1. Kasa bakiyesi
    public function test_cash_balance_card_shows_the_correct_value(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);
        CashTransaction::create([
            'type' => 'manual_out', 'direction' => 'out', 'amount' => 300, 'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
        $this->assertSame(700.0, $response->viewData('cashBalance'));
        $response->assertSee('700,00');
    }

    // 2. Toplam cari alacak/borç
    public function test_total_receivable_and_payable_cards_are_correct(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 1000, 'transaction_date' => now()]);
        $customer->transactions()->create(['type' => 'manual_credit', 'direction' => 'credit', 'amount' => 200, 'transaction_date' => now()]);

        $supplier = Account::factory()->create(['type' => 'supplier']);
        $supplier->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 500, 'transaction_date' => now()]);

        $other = Account::factory()->create(['type' => 'other']);
        $other->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
        // customer (1000-200=800) + other (100) = 900 receivable
        $this->assertSame(900.0, $response->viewData('totalReceivable'));
        // supplier debt = 500 payable
        $this->assertSame(500.0, $response->viewData('totalPayable'));
    }

    // Per-account balance() mantığı bozulmamalı (regresyon)
    public function test_individual_account_balance_is_unaffected_by_dashboard_aggregation(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 300, 'transaction_date' => now()]);

        $this->actingAs($this->admin())->get('/dashboard')->assertOk();

        $this->assertSame(300.0, $customer->fresh()->balance());
    }

    // 3/4. Bugünkü satış/alış, iptal edilmiş hariç
    public function test_today_sales_and_purchases_exclude_cancelled_records(): void
    {
        $admin = $this->admin();
        $product = $this->product(stock: 50, price: 100);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ]);
        $keptSale = Sale::first();

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $cancelledSale = Sale::latest('id')->first();
        $this->actingAs($admin)->post("/sales/{$cancelledSale->id}/cancel");

        $product2 = $this->product(stock: 0, price: 50);
        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product2->id, 'quantity' => 4, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $this->assertSame(200.0, $response->viewData('todaySales')); // only the kept sale (2*100)
        $this->assertSame(200.0, $response->viewData('todayPurchases')); // 4*50
    }

    // WP-10c: dashboard todaySales, reports/sales?period=today ile aynı gün
    // aralığını kullanıyor (Carbon::today()) — TL ve USD toplamları, iki
    // bağımsız sorgu yolu üzerinden birebir eşleşmeli; iptal edilen satış
    // ikisinde de hariç tutulmalı.
    public function test_dashboard_today_sales_matches_sales_report_today_totals_per_currency(): void
    {
        $admin = $this->admin();

        $tlProduct = $this->product(stock: 50, price: 100);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $tlProduct->id, 'quantity' => 2, 'unit_price' => 100]],
        ]);
        // kept TL sale: 2 * 100 = 200

        $usdProduct = Product::factory()->create([
            'currency' => 'USD', 'current_stock' => 50, 'sale_price' => 150, 'purchase_price' => 150,
        ]);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 150]],
        ]);
        // kept USD sale: 1 * 150 = 150

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $tlProduct->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $cancelledSale = Sale::latest('id')->first();
        $this->actingAs($admin)->post("/sales/{$cancelledSale->id}/cancel");
        // cancelled TL sale (100) must not land in either surface's total

        $dashboard = $this->actingAs($admin)->get('/dashboard');
        $dashboard->assertOk();

        $report = $this->actingAs($admin)->get('/reports/sales?period=today');
        $report->assertOk();

        $totalsByCurrency = $report->viewData('totalsByCurrency');
        $tlTotal = (float) $totalsByCurrency->firstWhere('currency', 'TL')->total;
        $usdTotal = (float) $totalsByCurrency->firstWhere('currency', 'USD')->total;

        $usdSummary = collect($dashboard->viewData('foreignCurrencySummaries'))->firstWhere('currency', 'USD');

        $this->assertSame(200.0, $dashboard->viewData('todaySales'));
        $this->assertSame(200.0, $tlTotal);
        $this->assertNotNull($usdSummary);
        $this->assertSame(150.0, $usdSummary['today_sales']);
        $this->assertSame(150.0, $usdTotal);
    }

    // WP-10c: alış tarafının aynısı — todayPurchases ile reports/purchases?period=today.
    public function test_dashboard_today_purchases_matches_purchases_report_today_totals_per_currency(): void
    {
        $admin = $this->admin();

        $tlProduct = $this->product(stock: 0, price: 80);
        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $tlProduct->id, 'quantity' => 5, 'unit_price' => 80]],
        ]);
        // kept TL purchase: 5 * 80 = 400

        $usdProduct = Product::factory()->create([
            'currency' => 'USD', 'current_stock' => 0, 'sale_price' => 60, 'purchase_price' => 60,
        ]);
        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 2, 'unit_price' => 60]],
        ]);
        // kept USD purchase: 2 * 60 = 120

        $anotherTlProduct = $this->product(stock: 0, price: 80);
        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $anotherTlProduct->id, 'quantity' => 3, 'unit_price' => 80]],
        ]);
        $cancelledPurchase = Purchase::latest('id')->first();
        $this->actingAs($admin)->post("/purchases/{$cancelledPurchase->id}/cancel");
        // cancelled TL purchase (240) must not land in either surface's total

        $dashboard = $this->actingAs($admin)->get('/dashboard');
        $dashboard->assertOk();

        $report = $this->actingAs($admin)->get('/reports/purchases?period=today');
        $report->assertOk();

        $totalsByCurrency = $report->viewData('totalsByCurrency');
        $tlTotal = (float) $totalsByCurrency->firstWhere('currency', 'TL')->total;
        $usdTotal = (float) $totalsByCurrency->firstWhere('currency', 'USD')->total;

        $usdSummary = collect($dashboard->viewData('foreignCurrencySummaries'))->firstWhere('currency', 'USD');

        $this->assertSame(400.0, $dashboard->viewData('todayPurchases'));
        $this->assertSame(400.0, $tlTotal);
        $this->assertNotNull($usdSummary);
        $this->assertSame(120.0, $usdSummary['today_purchases']);
        $this->assertSame(120.0, $usdTotal);
    }

    // 5/6. Bekleyen tahsilat/ödeme, iptal edilmiş hariç
    public function test_pending_collection_and_payment_exclude_cancelled_and_fully_paid_records(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $productA = $this->product(stock: 50, price: 100);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 300,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $productA->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        // 1000 total, 300 paid -> 700 remaining

        $productB = $this->product(stock: 50, price: 50);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $productB->id, 'quantity' => 2, 'unit_price' => 50]],
        ]);
        // fully paid -> should not contribute

        $productC = $this->product(stock: 50, price: 20);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $productC->id, 'quantity' => 5, 'unit_price' => 20]],
        ]);
        $cancelledSale = Sale::latest('id')->first();
        $this->actingAs($admin)->post("/sales/{$cancelledSale->id}/cancel");
        // cancelled -> should not contribute even though it was unpaid

        $productD = $this->product(stock: 0, price: 200);
        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $productD->id, 'quantity' => 5, 'unit_price' => 200]],
        ]);
        // 1000 total, unpaid -> 1000 remaining

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $this->assertSame(700.0, $response->viewData('pendingCollection'));
        $this->assertSame(1000.0, $response->viewData('pendingPayment'));
    }

    // Kritik stok ve son hareketler kartları regresyonu
    public function test_low_stock_and_recent_movements_cards_still_work(): void
    {
        $lowStockProduct = Product::factory()->create(['min_stock' => 10, 'current_stock' => 5, 'active' => true]);

        $this->actingAs($this->admin())->post('/stock-in', [
            'product_id' => $lowStockProduct->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
        $this->assertSame(1, $response->viewData('lowStockCount'));
        $this->assertTrue($response->viewData('recentMovements')->isNotEmpty());
    }

    // Yetkilendirme
    public function test_personel_can_view_the_dashboard(): void
    {
        $this->actingAs($this->personel())->get('/dashboard')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
