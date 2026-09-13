<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountTest extends TestCase
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

    // 1. Cari oluşturma
    public function test_admin_can_create_an_account_with_auto_generated_code(): void
    {
        $response = $this->actingAs($this->admin())->post('/accounts', [
            'type' => 'other',
            'name' => 'Genel Cari',
            'active' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accounts', [
            'name' => 'Genel Cari',
            'type' => 'other',
        ]);
        $this->assertNotNull(Account::first()->code);
    }

    // 2. Müşteri oluşturma
    public function test_admin_can_create_a_customer_account(): void
    {
        $this->actingAs($this->admin())->post('/accounts', [
            'type' => 'customer',
            'name' => 'ABC Ticaret',
            'phone' => '5551234567',
            'active' => 1,
        ]);

        $this->assertDatabaseHas('accounts', [
            'name' => 'ABC Ticaret',
            'type' => 'customer',
            'phone' => '5551234567',
        ]);
    }

    // 3. Tedarikçi oluşturma
    public function test_admin_can_create_a_supplier_account(): void
    {
        $this->actingAs($this->admin())->post('/accounts', [
            'type' => 'supplier',
            'name' => 'XYZ Tedarik',
            'tax_office' => 'Kadıköy',
            'tax_no' => '1234567890',
            'active' => 1,
        ]);

        $this->assertDatabaseHas('accounts', [
            'name' => 'XYZ Tedarik',
            'type' => 'supplier',
            'tax_office' => 'Kadıköy',
        ]);
    }

    // 4. Manuel cari borç
    public function test_manual_debt_increases_account_balance(): void
    {
        $account = Account::factory()->create();

        $response = $this->actingAs($this->admin())->post("/accounts/{$account->id}/transactions", [
            'type' => 'manual_debt',
            'amount' => 1000,
            'description' => 'Açılış devir bakiyesi',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('account_transactions', [
            'account_id' => $account->id,
            'type' => 'manual_debt',
            'direction' => 'debit',
            'amount' => 1000,
        ]);
        $this->assertSame(1000.0, $account->fresh()->balance());
        $this->assertSame(1000.0, $account->fresh()->totalDebt());
    }

    // 5. Manuel cari alacak
    public function test_manual_credit_decreases_account_balance(): void
    {
        $account = Account::factory()->create();
        $account->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 1000, 'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->post("/accounts/{$account->id}/transactions", [
            'type' => 'manual_credit',
            'amount' => 400,
            'description' => 'Kısmi mahsup',
        ]);

        $response->assertRedirect();
        $this->assertSame(600.0, $account->fresh()->balance());
        $this->assertSame(400.0, $account->fresh()->totalCredit());
    }

    public function test_manual_credit_can_push_balance_negative(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->admin())->post("/accounts/{$account->id}/transactions", [
            'type' => 'manual_credit',
            'amount' => 250,
        ]);

        $this->assertSame(-250.0, $account->fresh()->balance());
    }

    // Cancellation / reversal (section 3 of the WP-2 approval: no hard deletes)
    public function test_cancelling_a_transaction_creates_a_reversal_instead_of_deleting(): void
    {
        $account = Account::factory()->create();
        $transaction = $account->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 500, 'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->post("/account-transactions/{$transaction->id}/cancel");

        $response->assertRedirect();
        $this->assertDatabaseHas('account_transactions', ['id' => $transaction->id, 'amount' => 500]);
        $this->assertDatabaseHas('account_transactions', [
            'reversal_of_id' => $transaction->id,
            'direction' => 'credit',
            'amount' => 500,
        ]);
        $this->assertNotNull($transaction->fresh()->cancelled_at);
        $this->assertSame(0.0, $account->fresh()->balance());
        $this->assertSame(2, AccountTransaction::count());
    }

    public function test_a_transaction_cannot_be_cancelled_twice(): void
    {
        $account = Account::factory()->create();
        $transaction = $account->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 500, 'transaction_date' => now(),
        ]);

        $this->actingAs($this->admin())->post("/account-transactions/{$transaction->id}/cancel");
        $response = $this->actingAs($this->admin())->post("/account-transactions/{$transaction->id}/cancel");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(2, AccountTransaction::count());
    }

    // Guard against physical delete of accounts with financial history
    public function test_account_with_transactions_cannot_be_deleted(): void
    {
        $account = Account::factory()->create();
        $account->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->delete("/accounts/{$account->id}");

        $response->assertRedirect();
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    public function test_account_without_transactions_can_be_deleted(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->admin())->delete("/accounts/{$account->id}");

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    }

    // 16. Yetkisiz erişim
    public function test_non_admin_cannot_create_an_account(): void
    {
        $response = $this->actingAs($this->personel())->post('/accounts', [
            'type' => 'customer',
            'name' => 'Yetkisiz Cari',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('accounts', ['name' => 'Yetkisiz Cari']);
    }

    public function test_non_admin_cannot_add_a_manual_transaction(): void
    {
        $account = Account::factory()->create();

        $response = $this->actingAs($this->personel())->post("/accounts/{$account->id}/transactions", [
            'type' => 'manual_debt',
            'amount' => 100,
        ]);

        $response->assertForbidden();
        $this->assertSame(0.0, $account->fresh()->balance());
    }

    public function test_non_admin_cannot_cancel_a_transaction(): void
    {
        $account = Account::factory()->create();
        $transaction = $account->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 100, 'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->personel())->post("/account-transactions/{$transaction->id}/cancel");

        $response->assertForbidden();
        $this->assertNull($transaction->fresh()->cancelled_at);
    }

    public function test_guest_cannot_view_accounts(): void
    {
        $this->get('/accounts')->assertRedirect('/login');
    }

    public function test_non_admin_can_still_view_account_list_and_detail(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->personel())->get('/accounts')->assertOk();
        $this->actingAs($this->personel())->get("/accounts/{$account->id}")->assertOk();
    }

    /**
     * Regression guard: /accounts/create was previously swallowed by the
     * earlier-registered /accounts/{account} route (Laravel matched
     * "create" as the {account} wildcard), causing a 404 instead of the
     * create form.
     */
    public function test_admin_can_view_the_account_create_form(): void
    {
        $this->actingAs($this->admin())->get('/accounts/create')->assertOk();
    }

    public function test_non_admin_cannot_view_the_account_create_form(): void
    {
        $this->actingAs($this->personel())->get('/accounts/create')->assertForbidden();
    }

    private function product(int $stock = 100, float $price = 50): Product
    {
        return Product::factory()->create(['current_stock' => $stock, 'sale_price' => $price, 'purchase_price' => $price, 'currency' => 'TL']);
    }

    // WP-10d: Cari listesindeki vade uyarısı — Sale::overdue()/Purchase::overdue()
    // ile birebir aynı kural (due_date geçmiş + paid_amount < total + iptal değil).
    public function test_account_with_an_overdue_unpaid_sale_shows_the_warning_badge(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/accounts');

        $response->assertOk();
        $response->assertSee('Vadesi geçmiş bakiye var', false);
    }

    public function test_account_with_an_overdue_unpaid_purchase_shows_the_warning_badge(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/accounts');

        $response->assertOk();
        $response->assertSee('Vadesi geçmiş bakiye var', false);
    }

    public function test_account_with_a_fully_paid_past_due_sale_shows_no_warning_badge(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        // pesin always pays in full at creation, so remaining() is 0 even
        // though due_date is in the past — scopeOverdue() correctly excludes it.
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'pesin',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/accounts');

        $response->assertOk();
        $response->assertDontSee('Vadesi geçmiş bakiye var', false);
    }

    public function test_account_with_a_not_yet_due_sale_shows_no_warning_badge(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($admin)->get('/accounts');

        $response->assertOk();
        $response->assertDontSee('Vadesi geçmiş bakiye var', false);
    }
}
