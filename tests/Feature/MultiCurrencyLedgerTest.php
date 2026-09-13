<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class MultiCurrencyLedgerTest extends TestCase
{
    use RefreshDatabase;

    // 1. Migration / backfill: yeni currency kolonu var ve mevcut kayıtlar TL.
    public function test_currency_column_exists_and_defaults_to_tl_on_all_four_tables(): void
    {
        $account = Account::factory()->create();

        $sale = Sale::create([
            'number' => 'SAT-000001',
            'account_id' => $account->id,
            'payment_type' => 'pesin',
            'subtotal' => 100,
            'discount_total' => 0,
            'total' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
            'sale_date' => now(),
        ]);

        $purchase = Purchase::create([
            'number' => 'ALS-000001',
            'account_id' => $account->id,
            'payment_type' => 'pesin',
            'subtotal' => 100,
            'discount_total' => 0,
            'total' => 100,
            'paid_amount' => 100,
            'status' => 'paid',
            'purchase_date' => now(),
        ]);

        $accountTransaction = AccountTransaction::create([
            'account_id' => $account->id,
            'type' => 'manual_debt',
            'direction' => 'debit',
            'amount' => 50,
            'transaction_date' => now(),
        ]);

        $cashTransaction = CashTransaction::create([
            'type' => 'manual_in',
            'direction' => 'in',
            'amount' => 50,
            'transaction_date' => now(),
        ]);

        $this->assertSame('TL', $sale->fresh()->currency);
        $this->assertSame('TL', $purchase->fresh()->currency);
        $this->assertSame('TL', $accountTransaction->fresh()->currency);
        $this->assertSame('TL', $cashTransaction->fresh()->currency);
    }

    // 2. Account::balance() eski davranışla aynı sonucu vermeli (geriye uyum).
    public function test_account_balance_stays_backward_compatible_for_tl_only_account(): void
    {
        $account = Account::factory()->create();

        AccountTransaction::create([
            'account_id' => $account->id,
            'type' => 'manual_debt',
            'direction' => 'debit',
            'amount' => 1000,
            'transaction_date' => now(),
        ]);

        AccountTransaction::create([
            'account_id' => $account->id,
            'type' => 'collection',
            'direction' => 'credit',
            'amount' => 400,
            'transaction_date' => now(),
        ]);

        $this->assertSame(600.0, $account->balance());
        $this->assertSame(600.0, $account->balanceForCurrency('TL'));
    }

    // 3. Aynı carinin TL/USD/EUR bakiyeleri birbirinden tamamen bağımsız olmalı.
    public function test_account_holds_three_independent_currency_balances(): void
    {
        $account = Account::factory()->create();

        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 1000, 'currency' => 'TL', 'transaction_date' => now(),
        ]);
        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'collection', 'direction' => 'credit',
            'amount' => 200, 'currency' => 'TL', 'transaction_date' => now(),
        ]);

        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 500, 'currency' => 'USD', 'transaction_date' => now(),
        ]);

        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 300, 'currency' => 'EUR', 'transaction_date' => now(),
        ]);
        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'collection', 'direction' => 'credit',
            'amount' => 300, 'currency' => 'EUR', 'transaction_date' => now(),
        ]);

        $this->assertSame(800.0, $account->balanceForCurrency('TL'));
        $this->assertSame(500.0, $account->balanceForCurrency('USD'));
        $this->assertSame(0.0, $account->balanceForCurrency('EUR'));

        // TL üzerinden hesaplanan balance() değişmemeli, diğer para birimlerini karıştırmamalı.
        $this->assertSame(800.0, $account->balance());
    }

    // 4. balancesByCurrency() tüm hareketli para birimlerini tek sorguda döndürmeli.
    public function test_account_balances_by_currency_returns_every_active_currency(): void
    {
        $account = Account::factory()->create();

        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 100, 'currency' => 'TL', 'transaction_date' => now(),
        ]);
        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 50, 'currency' => 'USD', 'transaction_date' => now(),
        ]);

        $balances = $account->balancesByCurrency();

        $this->assertSame(100.0, $balances['TL']);
        $this->assertSame(50.0, $balances['USD']);
        $this->assertArrayNotHasKey('EUR', $balances->toArray());
    }

    // 5. Geçersiz para birimi için Account tarafında istisna fırlatılmalı.
    public function test_account_balance_for_currency_rejects_invalid_currency(): void
    {
        $account = Account::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $account->balanceForCurrency('GBP');
    }

    // 6. CashTransaction::balance() eski davranışla aynı sonucu vermeli (geriye uyum).
    public function test_cash_balance_stays_backward_compatible_for_tl_only_till(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000]);
        CashTransaction::factory()->create(['type' => 'manual_out', 'direction' => 'out', 'amount' => 250]);

        $this->assertSame(750.0, CashTransaction::balance());
        $this->assertSame(750.0, CashTransaction::balanceForCurrency('TL'));
    }

    // 7. Kasa da TL/USD/EUR için birbirinden bağımsız bakiye tutmalı.
    public function test_cash_holds_three_independent_currency_balances(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000, 'currency' => 'TL']);
        CashTransaction::factory()->create(['type' => 'manual_out', 'direction' => 'out', 'amount' => 400, 'currency' => 'TL']);

        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 200, 'currency' => 'USD']);

        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 90, 'currency' => 'EUR']);
        CashTransaction::factory()->create(['type' => 'manual_out', 'direction' => 'out', 'amount' => 90, 'currency' => 'EUR']);

        $this->assertSame(600.0, CashTransaction::balanceForCurrency('TL'));
        $this->assertSame(200.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('EUR'));
        $this->assertSame(600.0, CashTransaction::balance());
    }

    // 8. CashTransaction::balancesByCurrency() tüm hareketli para birimlerini döndürmeli.
    public function test_cash_balances_by_currency_returns_every_active_currency(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 300, 'currency' => 'TL']);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 75, 'currency' => 'USD']);

        $balances = CashTransaction::balancesByCurrency();

        $this->assertSame(300.0, $balances['TL']);
        $this->assertSame(75.0, $balances['USD']);
        $this->assertArrayNotHasKey('EUR', $balances->toArray());
    }

    // 9. Geçersiz para birimi için CashTransaction tarafında da istisna fırlatılmalı.
    public function test_cash_balance_for_currency_rejects_invalid_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CashTransaction::balanceForCurrency('GBP');
    }

    // 10. totalDebt()/totalCredit() ve totalIn()/totalOut() argümansız çağrıldığında eski (tüm para birimleri toplamı) davranışını korumalı.
    public function test_total_helpers_without_currency_argument_sum_across_all_currencies(): void
    {
        $account = Account::factory()->create();

        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 100, 'currency' => 'TL', 'transaction_date' => now(),
        ]);
        AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 50, 'currency' => 'USD', 'transaction_date' => now(),
        ]);

        $this->assertSame(150.0, $account->totalDebt());

        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 100, 'currency' => 'TL']);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 20, 'currency' => 'EUR']);

        $this->assertSame(120.0, CashTransaction::totalIn());
    }

    // 11. İptal (cancel) edilen bir hareketin ters kaydı, orijinal para birimini korumalı.
    public function test_cancelling_a_non_tl_transaction_reverses_in_the_same_currency(): void
    {
        $account = Account::factory()->create();

        $debt = AccountTransaction::create([
            'account_id' => $account->id, 'type' => 'manual_debt', 'direction' => 'debit',
            'amount' => 500, 'currency' => 'USD', 'transaction_date' => now(),
        ]);

        $reversal = $debt->cancel();

        $this->assertSame('USD', $reversal->currency);
        $this->assertSame(0.0, $account->balanceForCurrency('USD'));

        $cash = CashTransaction::create([
            'type' => 'manual_in', 'direction' => 'in', 'amount' => 200,
            'currency' => 'EUR', 'transaction_date' => now(),
        ]);

        $cashReversal = $cash->cancel();

        $this->assertSame('EUR', $cashReversal->currency);
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('EUR'));
    }

    // 12. Migration'ın kendisi mevcut satırları veri kaybı olmadan 'TL' ile doldurmalı.
    public function test_migration_backfills_pre_existing_rows_as_tl_without_altering_row_count(): void
    {
        $account = Account::factory()->create();

        DB::table('account_transactions')->insert([
            'account_id' => $account->id,
            'type' => 'manual_debt',
            'direction' => 'debit',
            'amount' => 250,
            'transaction_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('account_transactions')->first();

        $this->assertSame('TL', $row->currency);
        $this->assertSame(1, DB::table('account_transactions')->count());
    }
}
