<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
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
}
