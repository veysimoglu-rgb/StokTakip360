<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashTest extends TestCase
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

    // 1. Kasa manuel giriş
    public function test_manual_cash_in_increases_balance(): void
    {
        $response = $this->actingAs($this->admin())->post('/cash', [
            'type' => 'manual_in',
            'amount' => 500,
            'description' => 'Devir bakiyesi',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cash_transactions', ['type' => 'manual_in', 'direction' => 'in', 'amount' => 500]);
        $this->assertSame(500.0, CashTransaction::balance());
    }

    // 2. Kasa manuel çıkış
    public function test_manual_cash_out_decreases_balance(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);

        $this->actingAs($this->admin())->post('/cash', [
            'type' => 'manual_out',
            'amount' => 300,
        ]);

        $this->assertSame(700.0, CashTransaction::balance());
    }

    // 3. Diğer gelir
    public function test_other_income_increases_balance(): void
    {
        $this->actingAs($this->admin())->post('/cash', [
            'type' => 'other_income',
            'amount' => 250,
            'description' => 'Kira geliri',
        ]);

        $this->assertDatabaseHas('cash_transactions', ['type' => 'other_income', 'direction' => 'in']);
        $this->assertSame(250.0, CashTransaction::balance());
    }

    // 4. Gider
    public function test_expense_decreases_balance(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);

        $this->actingAs($this->admin())->post('/cash', [
            'type' => 'expense',
            'amount' => 150,
            'description' => 'Elektrik faturası',
        ]);

        $this->assertDatabaseHas('cash_transactions', ['type' => 'expense', 'direction' => 'out']);
        $this->assertSame(850.0, CashTransaction::balance());
    }

    // 5. Kasa bakiyesi (karışık hareketler)
    public function test_cash_balance_reflects_all_movements(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/cash', ['type' => 'manual_in', 'amount' => 1000]);
        $this->actingAs($admin)->post('/cash', ['type' => 'other_income', 'amount' => 200]);
        $this->actingAs($admin)->post('/cash', ['type' => 'expense', 'amount' => 300]);
        $this->actingAs($admin)->post('/cash', ['type' => 'manual_out', 'amount' => 100]);

        $this->assertSame(800.0, CashTransaction::balance());

        $response = $this->actingAs($admin)->get('/cash');
        $response->assertOk();
        $response->assertSee('800,00');
    }

    // 6 & 7 & 8. Müşteriden tahsilat + cari bakiyesi + kasa bakiyesi
    public function test_collecting_from_a_customer_reduces_debt_and_increases_cash(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 10000, 'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 3000,
            'description' => 'Kısmi tahsilat',
        ]);

        $response->assertRedirect();
        $this->assertSame(7000.0, $customer->fresh()->balance());
        $this->assertSame(3000.0, CashTransaction::balance());

        $accountTransaction = AccountTransaction::where('type', 'collection')->first();
        $cashTransaction = CashTransaction::where('type', 'collection')->first();

        $this->assertNotNull($accountTransaction);
        $this->assertNotNull($cashTransaction);
        $this->assertSame('credit', $accountTransaction->direction);
        $this->assertSame('in', $cashTransaction->direction);

        // Cross-linkage via the source morph, in both directions.
        $this->assertSame(CashTransaction::class, $accountTransaction->source_type);
        $this->assertSame($cashTransaction->id, $accountTransaction->source_id);
        $this->assertSame(AccountTransaction::class, $cashTransaction->source_type);
        $this->assertSame($accountTransaction->id, $cashTransaction->source_id);
    }

    // 9 & 10 & 11. Tedarikçiye ödeme + cari bakiyesi + kasa bakiyesi
    public function test_paying_a_supplier_reduces_debt_and_decreases_cash(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $supplier->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 20000, 'transaction_date' => now(),
        ]);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 50000]);

        $response = $this->actingAs($this->admin())->post("/accounts/{$supplier->id}/pay", [
            'amount' => 5000,
            'description' => 'Kısmi ödeme',
        ]);

        $response->assertRedirect();
        $this->assertSame(15000.0, $supplier->fresh()->balance());
        $this->assertSame(45000.0, CashTransaction::balance());

        $accountTransaction = AccountTransaction::where('type', 'payment')->first();
        $cashTransaction = CashTransaction::where('type', 'payment')->first();

        $this->assertSame('credit', $accountTransaction->direction);
        $this->assertSame('out', $cashTransaction->direction);
        $this->assertSame(CashTransaction::class, $accountTransaction->source_type);
        $this->assertSame($cashTransaction->id, $accountTransaction->source_id);
        $this->assertSame(AccountTransaction::class, $cashTransaction->source_type);
        $this->assertSame($accountTransaction->id, $cashTransaction->source_id);
    }

    // 12. Tahsilat iptali (her iki taraf da atomik şekilde geri alınır)
    public function test_cancelling_a_collection_reverses_both_account_and_cash_ledgers(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 10000, 'transaction_date' => now(),
        ]);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", ['amount' => 3000]);

        $accountTransaction = AccountTransaction::where('type', 'collection')->first();

        $response = $this->actingAs($this->admin())->post("/account-transactions/{$accountTransaction->id}/cancel");

        $response->assertRedirect();
        $this->assertSame(10000.0, $customer->fresh()->balance());
        $this->assertSame(0.0, CashTransaction::balance());
        $this->assertNotNull($accountTransaction->fresh()->cancelled_at);

        $cashTransaction = CashTransaction::where('type', 'collection')->first();
        $this->assertNotNull($cashTransaction->fresh()->cancelled_at);
    }

    // 13. Ödeme iptali (her iki taraf da atomik şekilde geri alınır, kasa tarafından tetiklense bile)
    public function test_cancelling_a_payment_from_the_cash_side_reverses_both_ledgers(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $supplier->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 20000, 'transaction_date' => now(),
        ]);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 50000]);

        $this->actingAs($this->admin())->post("/accounts/{$supplier->id}/pay", ['amount' => 5000]);

        $cashTransaction = CashTransaction::where('type', 'payment')->first();

        $response = $this->actingAs($this->admin())->post("/cash-transactions/{$cashTransaction->id}/cancel");

        $response->assertRedirect();
        $this->assertSame(20000.0, $supplier->fresh()->balance());
        $this->assertSame(50000.0, CashTransaction::balance());

        $accountTransaction = AccountTransaction::where('type', 'payment')->first();
        $this->assertNotNull($accountTransaction->fresh()->cancelled_at);
        $this->assertNotNull($cashTransaction->fresh()->cancelled_at);
    }

    // 14. Çift iptal engeli
    public function test_a_cash_transaction_cannot_be_cancelled_twice(): void
    {
        $transaction = CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500]);

        $this->actingAs($this->admin())->post("/cash-transactions/{$transaction->id}/cancel");
        $response = $this->actingAs($this->admin())->post("/cash-transactions/{$transaction->id}/cancel");

        $response->assertSessionHas('error');
        $this->assertSame(2, CashTransaction::count());
    }

    public function test_a_reversal_row_cannot_itself_be_cancelled(): void
    {
        $transaction = CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500]);
        $this->actingAs($this->admin())->post("/cash-transactions/{$transaction->id}/cancel");
        $reversal = CashTransaction::where('reversal_of_id', $transaction->id)->firstOrFail();

        $response = $this->actingAs($this->admin())->post("/cash-transactions/{$reversal->id}/cancel");

        $response->assertSessionHas('error');
        $this->assertSame(2, CashTransaction::count());
    }

    // 15. Personel'in finansal işlem oluşturamaması
    public function test_personel_cannot_create_manual_cash_transaction(): void
    {
        $response = $this->actingAs($this->personel())->post('/cash', [
            'type' => 'manual_in',
            'amount' => 100,
        ]);

        $response->assertForbidden();
        $this->assertSame(0.0, CashTransaction::balance());
    }

    public function test_personel_cannot_collect_or_pay(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $this->actingAs($this->personel())->post("/accounts/{$customer->id}/collect", ['amount' => 100])->assertForbidden();
        $this->actingAs($this->personel())->post("/accounts/{$supplier->id}/pay", ['amount' => 100])->assertForbidden();
        $this->assertSame(0.0, CashTransaction::balance());
    }

    public function test_personel_cannot_cancel_a_cash_transaction(): void
    {
        $transaction = CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500]);

        $response = $this->actingAs($this->personel())->post("/cash-transactions/{$transaction->id}/cancel");

        $response->assertForbidden();
        $this->assertNull($transaction->fresh()->cancelled_at);
    }

    // 16. Misafir erişimi
    public function test_guest_is_redirected_from_cash_page(): void
    {
        $this->get('/cash')->assertRedirect('/login');
    }

    public function test_personel_can_view_cash_page(): void
    {
        $this->actingAs($this->personel())->get('/cash')->assertOk();
    }

    // 17. Atomik transaction davranışı: geçersiz veri gönderilirse ne cari ne kasa tarafında kayıt oluşmamalı
    public function test_invalid_collection_request_creates_no_partial_records(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $response = $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => -50,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(0, CashTransaction::count());
    }

    public function test_collection_and_cash_rows_are_created_together_or_not_at_all(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", ['amount' => 1000]);

        $this->assertSame(1, AccountTransaction::count());
        $this->assertSame(1, CashTransaction::count());
    }

    private function product(string $currency = 'TL', int $stock = 10, float $price = 300): Product
    {
        return Product::factory()->create(['current_stock' => $stock, 'sale_price' => $price, 'purchase_price' => $price, 'currency' => $currency]);
    }

    // ===================== WP-10e: Tahsilat/Ödeme -> Sale/Purchase.paid_amount =====================

    // 1. Tam tahsilat: 300 TL satış, 225 TL peşin kısmi ödenmiş, vadesi geçmiş
    // (overdue). Kalan 75 TL'yi bu satışa bağlı tahsil edince: paid_amount
    // 300'e çıkmalı, status "paid" olmalı, Sale::overdue() artık bu satışı
    // döndürmemeli ve Cari listesindeki (WP-10d) ⚠️ rozeti kalkmalı.
    public function test_collection_applied_to_a_sale_fully_closes_it_and_clears_overdue_and_badge(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 225,
            'due_date' => now()->subDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $sale = Sale::first();

        $this->assertTrue(Sale::overdue()->whereKey($sale->id)->exists());
        $this->actingAs($admin)->get('/accounts')->assertSee('Vadesi geçmiş bakiye var', false);

        $response = $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 75,
            'sale_id' => $sale->id,
        ]);

        $response->assertRedirect();
        $sale->refresh();
        $this->assertSame(300.0, (float) $sale->paid_amount);
        $this->assertSame('paid', $sale->status);
        $this->assertSame(0.0, $sale->remaining());
        $this->assertFalse(Sale::overdue()->whereKey($sale->id)->exists());
        $this->actingAs($admin)->get('/accounts')->assertDontSee('Vadesi geçmiş bakiye var', false);
        // 225 (the sale's own initial "kismi" payment leg) + 75 (this collection).
        $this->assertSame(300.0, CashTransaction::balance());

        $accountTransaction = AccountTransaction::where('type', 'collection')->latest('id')->first();
        $this->assertSame(Sale::class, $accountTransaction->applies_to_type);
        $this->assertSame($sale->id, $accountTransaction->applies_to_id);
    }

    // 2. Kısmi tahsilat: belgeye bağlı olsa da kalanın tamamı kapanmayabilir.
    public function test_collection_applied_to_a_sale_can_partially_reduce_it(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 225,
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $sale = Sale::first();

        $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 50,
            'sale_id' => $sale->id,
        ]);

        $sale->refresh();
        $this->assertSame(275.0, (float) $sale->paid_amount);
        $this->assertSame(25.0, $sale->remaining());
        $this->assertSame('partial', $sale->status);
    }

    // 3. Kalan tutardan fazla tahsilat reddedilir, hiçbir kayıt oluşmaz.
    public function test_collection_exceeding_the_sales_remaining_balance_is_rejected(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 225,
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 100,
            'sale_id' => $sale->id,
        ]);

        $response->assertSessionHasErrors('sale_id');
        $this->assertSame(225.0, (float) $sale->fresh()->paid_amount);
        // Only the sale's own initial "kismi" payment leg exists — the
        // rejected request added no new row on either ledger.
        $this->assertSame(1, AccountTransaction::where('type', 'collection')->count());
        $this->assertSame(1, CashTransaction::where('type', 'collection')->count());
    }

    // 4. Farklı para birimindeki bir satışa bağlanamaz.
    public function test_collection_currency_must_match_the_selected_sales_currency(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $usdProduct = $this->product(currency: 'USD', price: 100);

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 50,
            'currency' => 'TL',
            'sale_id' => $sale->id,
        ]);

        $response->assertSessionHasErrors('sale_id');
        $this->assertSame(0.0, (float) $sale->fresh()->paid_amount);
        $this->assertSame(0, AccountTransaction::where('type', 'collection')->count());
    }

    // 5. Belge seçilmezse mevcut serbest/genel tahsilat davranışı hiç değişmez.
    public function test_collection_without_a_sale_id_behaves_exactly_as_before(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 225,
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", ['amount' => 500]);

        $response->assertRedirect();
        // 225 (the sale's own initial "kismi" payment leg) + 500 (this general collection).
        $this->assertSame(725.0, CashTransaction::balance());
        // The unrelated open sale must stay completely untouched.
        $this->assertSame(225.0, (float) $sale->fresh()->paid_amount);

        $accountTransaction = AccountTransaction::where('type', 'collection')->latest('id')->first();
        $this->assertSame(500.0, (float) $accountTransaction->amount);
        $this->assertNull($accountTransaction->applies_to_type);
        $this->assertNull($accountTransaction->applies_to_id);
    }

    // 6. Bağlı bir tahsilat iptal edilirse paid_amount ve status geri alınır.
    public function test_cancelling_an_applied_collection_reverses_the_sales_paid_amount(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $sale = Sale::first();

        $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 300,
            'sale_id' => $sale->id,
        ]);
        $this->assertSame('paid', $sale->fresh()->status);

        $accountTransaction = AccountTransaction::where('type', 'collection')->first();
        $response = $this->actingAs($admin)->post("/account-transactions/{$accountTransaction->id}/cancel");

        $response->assertRedirect();
        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->paid_amount);
        $this->assertSame('unpaid', $sale->status);
        $this->assertSame(0.0, CashTransaction::balance());
    }

    // 6b. Aynı iptal, kasa hareketi rotası üzerinden tetiklense bile aynı sonucu vermeli.
    public function test_cancelling_an_applied_collection_via_the_cash_route_also_reverses_paid_amount(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $sale = Sale::first();

        $this->actingAs($admin)->post("/accounts/{$customer->id}/collect", [
            'amount' => 300,
            'sale_id' => $sale->id,
        ]);

        $cashTransaction = CashTransaction::where('type', 'collection')->first();
        $this->actingAs($admin)->post("/cash-transactions/{$cashTransaction->id}/cancel");

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->paid_amount);
        $this->assertSame('unpaid', $sale->status);
    }

    // ===================== Purchase/Ödeme tarafının simetrik testleri =====================

    // 7. Ödeme, bir alışa bağlanınca kalanı kapatır ve overdue/rozet kalkar.
    public function test_payment_applied_to_a_purchase_fully_closes_it_and_clears_overdue_and_badge(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 225,
            'due_date' => now()->subDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $purchase = Purchase::first();

        $this->assertTrue(Purchase::overdue()->whereKey($purchase->id)->exists());
        $this->actingAs($admin)->get('/accounts')->assertSee('Vadesi geçmiş bakiye var', false);

        $response = $this->actingAs($admin)->post("/accounts/{$supplier->id}/pay", [
            'amount' => 75,
            'purchase_id' => $purchase->id,
        ]);

        $response->assertRedirect();
        $purchase->refresh();
        $this->assertSame(300.0, (float) $purchase->paid_amount);
        $this->assertSame('paid', $purchase->status);
        $this->assertFalse(Purchase::overdue()->whereKey($purchase->id)->exists());
        $this->actingAs($admin)->get('/accounts')->assertDontSee('Vadesi geçmiş bakiye var', false);

        $accountTransaction = AccountTransaction::where('type', 'payment')->latest('id')->first();
        $this->assertSame(Purchase::class, $accountTransaction->applies_to_type);
        $this->assertSame($purchase->id, $accountTransaction->applies_to_id);
    }

    // 8. Kalanı aşan ödeme reddedilir.
    public function test_payment_exceeding_the_purchases_remaining_balance_is_rejected(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 225,
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $purchase = Purchase::first();

        $response = $this->actingAs($admin)->post("/accounts/{$supplier->id}/pay", [
            'amount' => 100,
            'purchase_id' => $purchase->id,
        ]);

        $response->assertSessionHasErrors('purchase_id');
        $this->assertSame(225.0, (float) $purchase->fresh()->paid_amount);
    }

    // 9. Farklı para birimindeki bir alışa bağlanamaz.
    public function test_payment_currency_must_match_the_selected_purchases_currency(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $usdProduct = $this->product(currency: 'USD', stock: 0, price: 100);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $purchase = Purchase::first();

        $response = $this->actingAs($admin)->post("/accounts/{$supplier->id}/pay", [
            'amount' => 50,
            'currency' => 'TL',
            'purchase_id' => $purchase->id,
        ]);

        $response->assertSessionHasErrors('purchase_id');
        $this->assertSame(0.0, (float) $purchase->fresh()->paid_amount);
    }

    // 10. Bağlı bir ödeme iptal edilirse purchase.paid_amount geri alınır.
    public function test_cancelling_an_applied_payment_reverses_the_purchases_paid_amount(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 300]],
        ]);
        $purchase = Purchase::first();

        $this->actingAs($admin)->post("/accounts/{$supplier->id}/pay", [
            'amount' => 300,
            'purchase_id' => $purchase->id,
        ]);
        $this->assertSame('paid', $purchase->fresh()->status);

        $accountTransaction = AccountTransaction::where('type', 'payment')->first();
        $this->actingAs($admin)->post("/account-transactions/{$accountTransaction->id}/cancel");

        $purchase->refresh();
        $this->assertSame(0.0, (float) $purchase->paid_amount);
        $this->assertSame('unpaid', $purchase->status);
    }
}
