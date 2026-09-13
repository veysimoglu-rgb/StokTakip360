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

class ReportsWp6cTest extends TestCase
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

    // =========================== SATIŞ RAPORU ===========================

    public function test_sales_report_date_filter(): void
    {
        $admin = $this->admin();
        $inside = $this->product();
        $outside = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin', 'sale_date' => '2025-06-15',
            'items' => [['product_id' => $inside->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin', 'sale_date' => '2025-01-01',
            'items' => [['product_id' => $outside->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/sales?date_from=2025-06-01&date_to=2025-06-30');

        $response->assertOk();
        $numbers = $response->viewData('sales')->pluck('number');
        $this->assertCount(1, $numbers);
    }

    public function test_sales_report_account_filter(): void
    {
        $admin = $this->admin();
        $accountA = Account::factory()->create(['type' => 'customer']);
        $accountB = Account::factory()->create(['type' => 'customer']);
        $productA = $this->product();
        $productB = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $accountA->id, 'payment_type' => 'pesin',
            'items' => [['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $accountB->id, 'payment_type' => 'pesin',
            'items' => [['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get("/reports/sales?account_id={$accountA->id}&period=this_month");

        $response->assertOk();
        $this->assertCount(1, $response->viewData('sales'));
        $this->assertSame($accountA->id, $response->viewData('sales')->first()->account_id);
    }

    public function test_sales_report_status_filter(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $paidProduct = $this->product();
        $unpaidProduct = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $paidProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $unpaidProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/sales?status=unpaid&period=this_month');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('sales'));
        $this->assertSame('unpaid', $response->viewData('sales')->first()->status);
    }

    public function test_sales_report_overdue_filter(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $overdueProduct = $this->product();
        $notYetDueProduct = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'vadeli', 'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $overdueProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $notYetDueProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/sales?overdue=1&period=this_month');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('sales'));
        $this->assertTrue($response->viewData('sales')->first()->isOverdue());
    }

    public function test_cancelled_sale_appears_in_list_but_excluded_from_totals(): void
    {
        $admin = $this->admin();
        $product = $this->product(stock: 50, price: 100);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ]);
        $keptSale = Sale::first();

        $product2 = $this->product(stock: 50, price: 50);
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product2->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $cancelledSale = Sale::latest('id')->first();
        $this->actingAs($admin)->post("/sales/{$cancelledSale->id}/cancel");

        $response = $this->actingAs($admin)->get('/reports/sales?period=this_month');

        $response->assertOk();
        // İptal edilmiş satış listede var.
        $this->assertCount(2, $response->viewData('sales'));
        $this->assertTrue($response->viewData('sales')->contains('id', $cancelledSale->id));

        // Ama toplamlara dahil değil: sadece 200 (kept sale), 50 (cancelled) hariç.
        $totals = $response->viewData('totalsByCurrency')['TL'];
        $this->assertSame(200.0, (float) $totals->total);
        $this->assertSame(200.0, (float) $totals->paid);
        $this->assertSame(0.0, (float) $totals->remaining);
    }

    public function test_sales_report_totals_are_correct_with_partial_payment(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(stock: 50, price: 100);

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'kismi', 'paid_amount' => 300,
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/sales?period=this_month');

        $totals = $response->viewData('totalsByCurrency')['TL'];
        $this->assertSame(1000.0, (float) $totals->total);
        $this->assertSame(300.0, (float) $totals->paid);
        $this->assertSame(700.0, (float) $totals->remaining);
    }

    public function test_sales_report_csv_export(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/sales/export?period=this_month');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Satış No', $response->streamedContent());
    }

    public function test_sales_report_print_view_renders(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/sales/print?period=this_month');

        $response->assertOk();
        $response->assertSee('Satış Raporu');
    }

    // =========================== ALIŞ RAPORU ===========================

    public function test_purchases_report_date_filter(): void
    {
        $admin = $this->admin();
        $inside = $this->product(stock: 0);
        $outside = $this->product(stock: 0);

        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin', 'purchase_date' => '2025-06-15',
            'items' => [['product_id' => $inside->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin', 'purchase_date' => '2025-01-01',
            'items' => [['product_id' => $outside->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/purchases?date_from=2025-06-01&date_to=2025-06-30');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('purchases'));
    }

    public function test_purchases_report_account_filter(): void
    {
        $admin = $this->admin();
        $supplierA = Account::factory()->create(['type' => 'supplier']);
        $supplierB = Account::factory()->create(['type' => 'supplier']);
        $productA = $this->product(stock: 0);
        $productB = $this->product(stock: 0);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplierA->id, 'payment_type' => 'pesin',
            'items' => [['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplierB->id, 'payment_type' => 'pesin',
            'items' => [['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get("/reports/purchases?account_id={$supplierA->id}&period=this_month");

        $response->assertOk();
        $this->assertCount(1, $response->viewData('purchases'));
    }

    public function test_purchases_report_status_filter(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $paidProduct = $this->product(stock: 0);
        $unpaidProduct = $this->product(stock: 0);

        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $paidProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $unpaidProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/purchases?status=unpaid&period=this_month');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('purchases'));
    }

    public function test_purchases_report_overdue_filter(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $overdueProduct = $this->product(stock: 0);
        $notYetDueProduct = $this->product(stock: 0);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id, 'payment_type' => 'vadeli', 'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $overdueProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $notYetDueProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/reports/purchases?overdue=1&period=this_month');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('purchases'));
    }

    public function test_cancelled_purchase_appears_in_list_but_excluded_from_totals(): void
    {
        $admin = $this->admin();
        $product = $this->product(stock: 0, price: 200);

        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 200]],
        ]);
        $keptPurchase = Purchase::first();

        $product2 = $this->product(stock: 0, price: 50);
        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product2->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $cancelledPurchase = Purchase::latest('id')->first();
        $this->actingAs($admin)->post("/purchases/{$cancelledPurchase->id}/cancel");

        $response = $this->actingAs($admin)->get('/reports/purchases?period=this_month');

        $response->assertOk();
        $this->assertCount(2, $response->viewData('purchases'));
        $this->assertTrue($response->viewData('purchases')->contains('id', $cancelledPurchase->id));

        $totals = $response->viewData('totalsByCurrency')['TL'];
        $this->assertSame(400.0, (float) $totals->total);
        $this->assertSame(0.0, (float) $totals->remaining);
    }

    public function test_purchases_report_csv_export(): void
    {
        $product = $this->product(stock: 0);
        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/purchases/export?period=this_month');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Alış No', $response->streamedContent());
    }

    public function test_purchases_report_print_view_renders(): void
    {
        $product = $this->product(stock: 0);
        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/purchases/print?period=this_month');

        $response->assertOk();
        $response->assertSee('Alış Raporu');
    }

    // =========================== CARİ EKSTRE ===========================

    public function test_account_statement_requires_account_selection_shows_empty_state(): void
    {
        $response = $this->actingAs($this->admin())->get('/reports/account-statement');

        $response->assertOk();
        $response->assertSee('cari seçin', false);
    }

    public function test_account_statement_opening_balance_and_running_balance(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);

        // Before the report range: contributes to opening balance only.
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 1000,
            'transaction_date' => now()->subDays(40),
        ]);

        // Inside the range: two rows, running balance should walk forward correctly.
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 500,
            'transaction_date' => now()->subDays(10),
        ]);
        $customer->transactions()->create([
            'type' => 'manual_credit', 'direction' => 'credit', 'amount' => 300,
            'transaction_date' => now()->subDays(5),
        ]);

        $response = $this->actingAs($admin)->get("/reports/account-statement?account_id={$customer->id}&date_from=".now()->subDays(20)->toDateString().'&date_to='.now()->toDateString());

        $response->assertOk();
        $this->assertSame(1000.0, $response->viewData('openingBalance'));

        $rows = $response->viewData('rows');
        $this->assertCount(2, $rows);
        $this->assertSame(500.0, $rows[0]['debit']);
        $this->assertNull($rows[0]['credit']);
        $this->assertSame(1500.0, $rows[0]['balance']); // 1000 opening + 500 debit
        $this->assertNull($rows[1]['debit']);
        $this->assertSame(300.0, $rows[1]['credit']);
        $this->assertSame(1200.0, $rows[1]['balance']); // 1500 - 300
    }

    public function test_account_statement_resolves_sale_document_number_as_source(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($admin)->get("/reports/account-statement?account_id={$customer->id}&period=this_month");

        $response->assertOk();
        $row = $response->viewData('rows')->first();
        $this->assertSame($sale->number, $row['documentNumber']);
        $this->assertSame('Satış', $row['sourceLabel']);
        $response->assertSee($sale->number);
    }

    public function test_account_statement_csv_export(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get("/reports/account-statement/export?account_id={$customer->id}&period=this_month");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Açılış Bakiyesi', $response->streamedContent());
    }

    public function test_account_statement_export_without_account_redirects_with_error(): void
    {
        $response = $this->actingAs($this->admin())->get('/reports/account-statement/export');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_account_statement_print_view_renders(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create(['type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get("/reports/account-statement/print?account_id={$customer->id}&period=this_month");

        $response->assertOk();
        $response->assertSee('Cari Ekstre');
    }

    // =========================== KASA RAPORU ===========================

    public function test_cash_report_date_filter_and_totals(): void
    {
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'transaction_date' => '2025-06-15']);
        CashTransaction::create(['type' => 'manual_out', 'direction' => 'out', 'amount' => 200, 'transaction_date' => '2025-06-16']);
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 9999, 'transaction_date' => '2025-01-01']);

        $response = $this->actingAs($this->admin())->get('/reports/cash?date_from=2025-06-01&date_to=2025-06-30');

        $response->assertOk();
        $totals = $response->viewData('totalsByCurrency')['TL'];
        $this->assertSame(500.0, $totals['in']);
        $this->assertSame(200.0, $totals['out']);
        $this->assertSame(300.0, $totals['net']);
        $this->assertCount(2, $response->viewData('transactions'));
    }

    public function test_cash_report_reflects_reversal_in_the_period_it_was_cancelled_in(): void
    {
        $admin = $this->admin();

        $transaction = CashTransaction::create([
            'type' => 'manual_in', 'direction' => 'in', 'amount' => 1000, 'transaction_date' => '2025-06-01',
        ]);

        // Cancelled "today" (not backdated) — the reversal's own transaction_date is now(), per CashTransaction::cancel().
        $this->actingAs($admin)->post("/cash-transactions/{$transaction->id}/cancel");

        // June report: only the original +1000 appears; the reversal is dated today, not June.
        $juneResponse = $this->actingAs($admin)->get('/reports/cash?date_from=2025-06-01&date_to=2025-06-30');
        $juneResponse->assertOk();
        $juneTotals = $juneResponse->viewData('totalsByCurrency')['TL'];
        $this->assertSame(1000.0, $juneTotals['in']);
        $this->assertSame(0.0, $juneTotals['out']);

        // Today's report: the reversal (-1000, direction out) appears here instead.
        $todayResponse = $this->actingAs($admin)->get('/reports/cash?period=today');
        $todayResponse->assertOk();
        $this->assertSame(1000.0, $todayResponse->viewData('totalsByCurrency')['TL']['out']);
    }

    public function test_cash_report_csv_export(): void
    {
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/cash/export?period=this_month');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Hareket Tipi', $response->streamedContent());
    }

    public function test_cash_report_print_view_renders(): void
    {
        CashTransaction::create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'transaction_date' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/cash/print?period=this_month');

        $response->assertOk();
        $response->assertSee('Kasa Raporu');
    }

    // =========================== YETKİ ===========================

    public function test_personel_can_view_all_new_reports(): void
    {
        $personel = $this->personel();

        $this->actingAs($personel)->get('/reports/sales')->assertOk();
        $this->actingAs($personel)->get('/reports/purchases')->assertOk();
        $this->actingAs($personel)->get('/reports/account-statement')->assertOk();
        $this->actingAs($personel)->get('/reports/cash')->assertOk();
    }

    public function test_guest_is_redirected_from_all_new_reports(): void
    {
        $this->get('/reports/sales')->assertRedirect('/login');
        $this->get('/reports/purchases')->assertRedirect('/login');
        $this->get('/reports/account-statement')->assertRedirect('/login');
        $this->get('/reports/cash')->assertRedirect('/login');
    }

    public function test_reports_index_lists_the_new_reports(): void
    {
        $response = $this->actingAs($this->admin())->get('/reports');

        $response->assertOk();
        $response->assertSee('Satış Raporu');
        $response->assertSee('Alış Raporu');
        $response->assertSee('Cari Ekstre');
        $response->assertSee('Kasa Raporu');
    }
}
