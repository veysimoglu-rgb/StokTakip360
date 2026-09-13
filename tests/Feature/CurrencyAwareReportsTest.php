<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CurrencyAwareReportsTest extends TestCase
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

    private function product(string $currency, int $stock = 50, float $price = 100): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'sale_price' => $price,
            'purchase_price' => $price,
            'currency' => $currency,
        ]);
    }

    // =========================== DASHBOARD ===========================

    public function test_dashboard_usd_activity_shows_a_separate_summary_without_blending_into_tl_cards(): void
    {
        $admin = $this->admin();
        $tlProduct = $this->product('TL', price: 100);
        $usdProduct = $this->product('USD', price: 200);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $tlProduct->id, 'quantity' => 2, 'unit_price' => 100]],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 3, 'unit_price' => 200]],
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        // TL card stays exactly TL — the USD sale never adds into it.
        $this->assertSame(200.0, $response->viewData('todaySales'));
        $this->assertSame(200.0, $response->viewData('cashBalance'));

        $summaries = $response->viewData('foreignCurrencySummaries');
        $usdSummary = $summaries->firstWhere('currency', 'USD');
        $this->assertNotNull($usdSummary);
        $this->assertSame(600.0, $usdSummary['today_sales']);
        $this->assertSame(600.0, $usdSummary['kasa']);
        $this->assertNull($summaries->firstWhere('currency', 'EUR'));
    }

    public function test_dashboard_never_combines_tl_and_usd_into_one_number(): void
    {
        $admin = $this->admin();
        $tlProduct = $this->product('TL', price: 1000);
        $usdProduct = $this->product('USD', price: 700);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $tlProduct->id, 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 700]],
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');

        // The forbidden "1.700" blended total must never appear as todaySales.
        $this->assertSame(1000.0, $response->viewData('todaySales'));
        $this->assertNotEquals(1700.0, $response->viewData('todaySales'));
    }

    public function test_dashboard_overdue_amounts_are_currency_separated(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $tlProduct = $this->product('TL', price: 100);
        $usdProduct = $this->product('USD', price: 50);

        foreach ([$tlProduct, $usdProduct] as $product) {
            $this->actingAs($admin)->post('/sales', [
                'account_id' => $customer->id,
                'payment_type' => 'vadeli',
                'due_date' => now()->subDays(5)->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => $product->sale_price]],
            ]);
        }

        $response = $this->actingAs($admin)->get('/dashboard');

        $this->assertSame(200.0, $response->viewData('overdueSalesAmount'));
        $usdSummary = $response->viewData('foreignCurrencySummaries')->firstWhere('currency', 'USD');
        $this->assertSame(100.0, $usdSummary['overdue_sales']);
    }

    public function test_dashboard_with_no_foreign_activity_shows_no_foreign_summaries(): void
    {
        $response = $this->actingAs($this->admin())->get('/dashboard');

        $this->assertTrue($response->viewData('foreignCurrencySummaries')->isEmpty());
    }

    // =========================== CARİ LİSTESİ ===========================

    public function test_account_list_shows_tl_usd_eur_balances_separately(): void
    {
        $account = Account::factory()->create(['type' => 'customer']);
        $account->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 15000, 'currency' => 'TL', 'transaction_date' => now()]);
        $account->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 1200, 'currency' => 'USD', 'transaction_date' => now()]);
        $account->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 500, 'currency' => 'EUR', 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/accounts');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('TL: 15.000,00', $content);
        $this->assertStringContainsString('USD: 1.200,00', $content);
        $this->assertStringContainsString('EUR: 500,00', $content);
        // The forbidden blended "16.700" must never be rendered.
        $this->assertStringNotContainsString('16.700,00', $content);
    }

    public function test_account_list_shows_balances_for_every_row_on_the_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $account = Account::factory()->create(['type' => 'customer']);
            $account->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'transaction_date' => now()]);
        }

        $response = $this->actingAs($this->admin())->get('/accounts');

        $response->assertOk();
        $this->assertCount(5, $response->viewData('accounts'));
        $this->assertCount(5, $response->viewData('balancesByAccount'));
    }

    // =========================== SATIŞ RAPORU ===========================

    public function test_sales_report_currency_filter_returns_only_matching_currency(): void
    {
        $admin = $this->admin();
        $tlProduct = $this->product('TL');
        $usdProduct = $this->product('USD');

        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $tlProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);
        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);

        $response = $this->actingAs($admin)->get('/reports/sales?period=this_month&currency=USD');

        $response->assertOk();
        $sales = $response->viewData('sales');
        $this->assertCount(1, $sales);
        $this->assertSame('USD', $sales->first()->currency);
    }

    public function test_sales_report_totals_are_separated_by_currency(): void
    {
        $admin = $this->admin();
        $tlProduct = $this->product('TL', price: 100);
        $usdProduct = $this->product('USD', price: 50);

        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $tlProduct->id, 'quantity' => 2, 'unit_price' => 100]]]);
        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $usdProduct->id, 'quantity' => 3, 'unit_price' => 50]]]);

        $response = $this->actingAs($admin)->get('/reports/sales?period=this_month');

        $totals = $response->viewData('totalsByCurrency');
        $this->assertSame(200.0, (float) $totals['TL']->total);
        $this->assertSame(150.0, (float) $totals['USD']->total);
    }

    public function test_sales_report_paid_and_remaining_are_computed_per_currency(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $usdProduct = $this->product('USD', price: 100);

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 300,
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/sales?period=this_month');

        $totals = $response->viewData('totalsByCurrency')['USD'];
        $this->assertSame(1000.0, (float) $totals->total);
        $this->assertSame(300.0, (float) $totals->paid);
        $this->assertSame(700.0, (float) $totals->remaining);
    }

    public function test_sales_report_csv_export_includes_currency_column(): void
    {
        $admin = $this->admin();
        $usdProduct = $this->product('USD');
        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);

        $response = $this->actingAs($admin)->get('/reports/sales/export?period=this_month');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Para Birimi', $content);
        $this->assertStringContainsString('USD', $content);
    }

    public function test_sales_report_print_shows_totals_per_currency(): void
    {
        $admin = $this->admin();
        $usdProduct = $this->product('USD');
        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);

        $response = $this->actingAs($admin)->get('/reports/sales/print?period=this_month');

        $response->assertOk();
        $response->assertSee('TOPLAM (USD)');
    }

    // =========================== ALIŞ RAPORU ===========================

    public function test_purchases_report_currency_filter_and_totals(): void
    {
        $admin = $this->admin();
        $tlProduct = $this->product('TL', price: 100);
        $eurProduct = $this->product('EUR', price: 80);

        $this->actingAs($admin)->post('/purchases', ['payment_type' => 'pesin', 'items' => [['product_id' => $tlProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);
        $this->actingAs($admin)->post('/purchases', ['payment_type' => 'pesin', 'items' => [['product_id' => $eurProduct->id, 'quantity' => 2, 'unit_price' => 80]]]);

        $response = $this->actingAs($admin)->get('/reports/purchases?period=this_month&currency=EUR');

        $purchases = $response->viewData('purchases');
        $this->assertCount(1, $purchases);
        $this->assertSame('EUR', $purchases->first()->currency);

        $totals = $response->viewData('totalsByCurrency');
        $this->assertSame(160.0, (float) $totals['EUR']->total);
        $this->assertArrayNotHasKey('TL', $totals->toArray());
    }

    public function test_purchases_report_csv_export_includes_currency_column(): void
    {
        $admin = $this->admin();
        $eurProduct = $this->product('EUR');
        $this->actingAs($admin)->post('/purchases', ['payment_type' => 'pesin', 'items' => [['product_id' => $eurProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);

        $response = $this->actingAs($admin)->get('/reports/purchases/export?period=this_month');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Para Birimi', $content);
        $this->assertStringContainsString('EUR', $content);
    }

    public function test_purchases_report_print_shows_totals_per_currency(): void
    {
        $admin = $this->admin();
        $eurProduct = $this->product('EUR');
        $this->actingAs($admin)->post('/purchases', ['payment_type' => 'pesin', 'items' => [['product_id' => $eurProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);

        $response = $this->actingAs($admin)->get('/reports/purchases/print?period=this_month');

        $response->assertOk();
        $response->assertSee('TOPLAM (EUR)');
    }

    // =========================== CARİ EKSTRE ===========================

    public function test_account_statement_defaults_to_tl_and_shows_correct_running_balance(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 1000, 'currency' => 'TL', 'transaction_date' => now()->subDays(5)]);

        $response = $this->actingAs($admin)->get("/reports/account-statement?account_id={$customer->id}&period=this_month");

        $response->assertOk();
        $this->assertSame('TL', $response->viewData('currency'));
        $rows = $response->viewData('rows');
        $this->assertSame(1000.0, $rows[0]['balance']);
    }

    public function test_account_statement_usd_currency_has_its_own_opening_and_running_balance(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 500, 'currency' => 'USD',
            'transaction_date' => now()->subDays(20),
        ]);
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 200, 'currency' => 'USD',
            'transaction_date' => now()->subDays(5),
        ]);

        $response = $this->actingAs($admin)->get("/reports/account-statement?account_id={$customer->id}&currency=USD&date_from=".now()->subDays(10)->toDateString().'&date_to='.now()->toDateString());

        $response->assertOk();
        $this->assertSame(500.0, $response->viewData('openingBalance'));
        $rows = $response->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(700.0, $rows[0]['balance']);
    }

    public function test_account_statement_tl_and_usd_on_the_same_account_never_affect_each_other(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 1000, 'currency' => 'TL', 'transaction_date' => now()]);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 300, 'currency' => 'USD', 'transaction_date' => now()]);

        $tlResponse = $this->actingAs($admin)->get("/reports/account-statement?account_id={$customer->id}&currency=TL&period=this_month");
        $usdResponse = $this->actingAs($admin)->get("/reports/account-statement?account_id={$customer->id}&currency=USD&period=this_month");

        $this->assertCount(1, $tlResponse->viewData('rows'));
        $this->assertSame(1000.0, $tlResponse->viewData('rows')[0]['balance']);

        $this->assertCount(1, $usdResponse->viewData('rows'));
        $this->assertSame(300.0, $usdResponse->viewData('rows')[0]['balance']);
    }

    public function test_account_statement_csv_export_includes_currency_column(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'currency' => 'USD', 'transaction_date' => now()]);

        $response = $this->actingAs($admin)->get("/reports/account-statement/export?account_id={$customer->id}&currency=USD&period=this_month");

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Para Birimi', $content);
        $this->assertStringContainsString('USD', $content);
    }

    public function test_account_statement_print_shows_the_selected_currency(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'currency' => 'EUR', 'transaction_date' => now()]);

        $response = $this->actingAs($admin)->get("/reports/account-statement/print?account_id={$customer->id}&currency=EUR&period=this_month");

        $response->assertOk();
        $response->assertSee('Para Birimi: EUR', false);
    }

    // =========================== KASA ===========================

    public function test_cash_index_shows_tl_and_usd_balances_separately(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000, 'currency' => 'TL']);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'currency' => 'USD']);

        $response = $this->actingAs($this->admin())->get('/cash');

        $response->assertOk();
        $summaries = $response->viewData('cashSummaries');
        $tl = $summaries->firstWhere('currency', 'TL');
        $usd = $summaries->firstWhere('currency', 'USD');

        $this->assertSame(1000.0, $tl['balance']);
        $this->assertSame(500.0, $usd['balance']);
        $this->assertNull($summaries->firstWhere('currency', 'EUR'));
    }

    public function test_cash_index_today_in_out_are_computed_per_currency(): void
    {
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 300, 'currency' => 'TL', 'transaction_date' => now()]);
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 150, 'currency' => 'USD', 'transaction_date' => now()]);
        CashTransaction::create(['type' => 'manual_out', 'direction' => 'out', 'amount' => 50, 'currency' => 'USD', 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/cash');

        $summaries = $response->viewData('cashSummaries');
        $usd = $summaries->firstWhere('currency', 'USD');

        $this->assertSame(150.0, $usd['today_in']);
        $this->assertSame(50.0, $usd['today_out']);
    }

    // =========================== KASA RAPORU ===========================

    public function test_cash_report_totals_are_separated_by_currency(): void
    {
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'currency' => 'TL', 'transaction_date' => now()]);
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 200, 'currency' => 'USD', 'transaction_date' => now()]);
        CashTransaction::create(['type' => 'manual_out', 'direction' => 'out', 'amount' => 50, 'currency' => 'USD', 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/cash?period=today');

        $totals = $response->viewData('totalsByCurrency');
        $this->assertSame(500.0, $totals['TL']['in']);
        $this->assertSame(200.0, $totals['USD']['in']);
        $this->assertSame(50.0, $totals['USD']['out']);
        $this->assertSame(150.0, $totals['USD']['net']);
    }

    public function test_cash_report_reversal_lands_in_correct_currency_and_period(): void
    {
        $admin = $this->admin();
        $transaction = CashTransaction::create([
            'type' => 'manual_in', 'direction' => 'in', 'amount' => 400, 'currency' => 'USD', 'transaction_date' => now(),
        ]);

        $this->actingAs($admin)->post("/cash-transactions/{$transaction->id}/cancel");

        $response = $this->actingAs($admin)->get('/reports/cash?period=today');

        $totals = $response->viewData('totalsByCurrency')['USD'];
        $this->assertSame(400.0, $totals['in']);
        $this->assertSame(400.0, $totals['out']);
        $this->assertSame(0.0, $totals['net']);
    }

    public function test_cash_report_csv_export_includes_currency_column(): void
    {
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 400, 'currency' => 'USD', 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/cash/export?period=today');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Para Birimi', $content);
        $this->assertStringContainsString('USD', $content);
    }

    // =========================== YETKİ / REGRESYON ===========================

    public function test_personel_can_view_all_currency_aware_pages(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $personel = User::factory()->create();
        $personel->assignRole('Personel');

        $this->actingAs($personel)->get('/dashboard')->assertOk();
        $this->actingAs($personel)->get('/accounts')->assertOk();
        $this->actingAs($personel)->get('/reports/sales')->assertOk();
        $this->actingAs($personel)->get('/reports/purchases')->assertOk();
        $this->actingAs($personel)->get('/reports/cash')->assertOk();
        $this->actingAs($personel)->get('/cash')->assertOk();
    }
}
