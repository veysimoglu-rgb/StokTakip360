<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashLedgerCurrencyTest extends TestCase
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

    // --- TAHSİLAT: TL / USD / EUR ---------------------------------------

    public function test_tl_collection_records_tl_on_both_ledgers(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 1000,
            'currency' => 'TL',
        ])->assertRedirect();

        $this->assertSame(-1000.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(1000.0, CashTransaction::balanceForCurrency('TL'));
        $this->assertSame('TL', AccountTransaction::first()->currency);
        $this->assertSame('TL', CashTransaction::first()->currency);
    }

    public function test_usd_collection_records_usd_on_both_ledgers(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 500,
            'currency' => 'USD',
        ])->assertRedirect();

        $this->assertSame(-500.0, $customer->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(500.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame('USD', AccountTransaction::first()->currency);
        $this->assertSame('USD', CashTransaction::first()->currency);
    }

    public function test_eur_collection_records_eur_on_both_ledgers(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 250,
            'currency' => 'EUR',
        ])->assertRedirect();

        $this->assertSame(-250.0, $customer->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(250.0, CashTransaction::balanceForCurrency('EUR'));
    }

    public function test_collection_without_currency_defaults_to_tl_for_backward_compatibility(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 1000,
        ])->assertRedirect();

        $this->assertSame('TL', AccountTransaction::first()->currency);
        $this->assertSame('TL', CashTransaction::first()->currency);
    }

    public function test_collection_rejects_an_unsupported_currency(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 1000,
            'currency' => 'GBP',
        ])->assertSessionHasErrors('currency');

        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(0, CashTransaction::count());
    }

    // --- ÖDEME: TL / USD / EUR -------------------------------------------

    public function test_tl_payment_records_tl_on_both_ledgers(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 5000, 'currency' => 'TL']);

        $this->actingAs($this->admin())->post("/accounts/{$supplier->id}/pay", [
            'amount' => 1000,
            'currency' => 'TL',
        ])->assertRedirect();

        $this->assertSame(-1000.0, $supplier->fresh()->balanceForCurrency('TL'));
        $this->assertSame(4000.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_usd_payment_records_usd_on_both_ledgers(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $this->actingAs($this->admin())->post("/accounts/{$supplier->id}/pay", [
            'amount' => 500,
            'currency' => 'USD',
        ])->assertRedirect();

        $this->assertSame(-500.0, $supplier->fresh()->balanceForCurrency('USD'));
        $this->assertSame(-500.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame('USD', AccountTransaction::first()->currency);
        $this->assertSame('USD', CashTransaction::first()->currency);
    }

    public function test_eur_payment_records_eur_on_both_ledgers(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $this->actingAs($this->admin())->post("/accounts/{$supplier->id}/pay", [
            'amount' => 250,
            'currency' => 'EUR',
        ])->assertRedirect();

        $this->assertSame(-250.0, $supplier->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(-250.0, CashTransaction::balanceForCurrency('EUR'));
    }

    // --- Reversal preserves currency --------------------------------------

    public function test_cancelling_a_usd_collection_reverses_both_ledgers_in_usd(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", [
            'amount' => 500,
            'currency' => 'USD',
        ]);

        $accountTransaction = AccountTransaction::first();
        $this->actingAs($this->admin())->post("/account-transactions/{$accountTransaction->id}/cancel")->assertRedirect();

        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('USD'));

        foreach (AccountTransaction::all() as $transaction) {
            $this->assertSame('USD', $transaction->currency);
        }
        foreach (CashTransaction::all() as $transaction) {
            $this->assertSame('USD', $transaction->currency);
        }
    }

    public function test_cancelling_a_eur_payment_reverses_both_ledgers_in_eur(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $this->actingAs($this->admin())->post("/accounts/{$supplier->id}/pay", [
            'amount' => 250,
            'currency' => 'EUR',
        ]);

        $cashTransaction = CashTransaction::first();
        $this->actingAs($this->admin())->post("/cash-transactions/{$cashTransaction->id}/cancel")->assertRedirect();

        $this->assertSame(0.0, $supplier->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('EUR'));
    }

    // --- Cari: farklı currency'ler birbirini etkilemez --------------------

    public function test_same_account_tracks_tl_usd_and_eur_completely_independently(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);

        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", ['amount' => 10000, 'currency' => 'TL']);
        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", ['amount' => 1500, 'currency' => 'USD']);
        $this->actingAs($this->admin())->post("/accounts/{$customer->id}/collect", ['amount' => 500, 'currency' => 'EUR']);

        $this->assertSame(-10000.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(-1500.0, $customer->fresh()->balanceForCurrency('USD'));
        $this->assertSame(-500.0, $customer->fresh()->balanceForCurrency('EUR'));

        $balances = $customer->fresh()->balancesByCurrency();
        $this->assertSame(-10000.0, $balances['TL']);
        $this->assertSame(-1500.0, $balances['USD']);
        $this->assertSame(-500.0, $balances['EUR']);
    }

    // --- KASA: manuel form + currency kolonu -----------------------------

    public function test_manual_cash_movement_accepts_an_explicit_currency(): void
    {
        $this->actingAs($this->admin())->post('/cash', [
            'type' => 'manual_in',
            'amount' => 750,
            'currency' => 'USD',
        ])->assertRedirect();

        $this->assertSame('USD', CashTransaction::first()->currency);
        $this->assertSame(750.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_manual_cash_movement_without_currency_defaults_to_tl(): void
    {
        $this->actingAs($this->admin())->post('/cash', [
            'type' => 'manual_in',
            'amount' => 500,
        ])->assertRedirect();

        $this->assertSame('TL', CashTransaction::first()->currency);
    }

    public function test_manual_cash_movement_rejects_an_unsupported_currency(): void
    {
        $this->actingAs($this->admin())->post('/cash', [
            'type' => 'manual_in',
            'amount' => 500,
            'currency' => 'GBP',
        ])->assertSessionHasErrors('currency');

        $this->assertSame(0, CashTransaction::count());
    }

    public function test_cash_index_shows_a_currency_column_and_the_real_row_currency(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'currency' => 'USD']);

        $content = $this->actingAs($this->admin())->get('/cash')->getContent();

        $this->assertStringContainsString('Para Birimi', $content);
        $this->assertStringContainsString('>USD<', $content);
    }

    // --- Static HTML: currency dropdowns are present on the right forms --

    public function test_cash_manual_form_shows_a_currency_dropdown_defaulting_to_tl(): void
    {
        $content = $this->actingAs($this->admin())->get('/cash')->getContent();

        $this->assertStringContainsString('name="currency"', $content);
        $this->assertStringContainsString('selected', $content);
    }

    public function test_account_show_page_shows_currency_dropdown_on_both_collect_and_pay_forms(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $customerContent = $this->actingAs($this->admin())->get("/accounts/{$customer->id}")->getContent();
        $this->assertStringContainsString('id="collect_currency"', $customerContent);

        $supplierContent = $this->actingAs($this->admin())->get("/accounts/{$supplier->id}")->getContent();
        $this->assertStringContainsString('id="pay_currency"', $supplierContent);
    }

    // --- Static HTML: sales/create & purchases/create currency UX --------

    public function test_sale_create_form_shows_currency_indicator_and_disables_mismatched_products(): void
    {
        $content = $this->actingAs($this->admin())->get('/sales/create')->getContent();

        $this->assertStringContainsString('documentCurrency()', $content);
        $this->assertStringContainsString('isDisabledProduct(p, index)', $content);
        // The earlier required+min bug must never come back: no x-show on a
        // required field, still using <template x-if> for it.
        $this->assertStringNotContainsString("x-show=\"paymentType === 'kismi'\"", $content);
    }

    public function test_purchase_create_form_shows_currency_indicator_and_disables_mismatched_products(): void
    {
        $content = $this->actingAs($this->admin())->get('/purchases/create')->getContent();

        $this->assertStringContainsString('documentCurrency()', $content);
        $this->assertStringContainsString('isDisabledProduct(p, index)', $content);
        $this->assertStringNotContainsString("x-show=\"paymentType === 'kismi'\"", $content);
    }
}
