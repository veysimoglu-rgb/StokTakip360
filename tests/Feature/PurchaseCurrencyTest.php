<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseCurrencyTest extends TestCase
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

    private function product(string $currency, int $stock = 0, float $price = 100): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'purchase_price' => $price,
            'currency' => $currency,
        ]);
    }

    private function assertDocumentCurrency(Purchase $purchase, string $currency): void
    {
        $this->assertSame($currency, $purchase->currency);
        $this->assertSame($currency, StockMovement::where('source_type', Purchase::class)->where('source_id', $purchase->id)->first()->currency);

        if ($purchase->debtAccountTransaction) {
            $this->assertSame($currency, $purchase->debtAccountTransaction->currency);
        }

        if ($purchase->paymentAccountTransaction) {
            $this->assertSame($currency, $purchase->paymentAccountTransaction->currency);
        }

        if ($purchase->cashTransaction) {
            $this->assertSame($currency, $purchase->cashTransaction->currency);
        }
    }

    // --- TL/USD/EUR x peşin/kısmi/vadeli --------------------------------

    public function test_tl_pesin_purchase_records_tl_everywhere_and_moves_stock(): void
    {
        $product = $this->product('TL', stock: 5, price: 100);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'TL');
        $this->assertSame(300.0, (float) $purchase->total);
        $this->assertSame(8.0, (float) $product->fresh()->current_stock);
        $this->assertSame(-300.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_usd_pesin_purchase_records_usd_everywhere_and_moves_stock(): void
    {
        $product = $this->product('USD', stock: 5, price: 100);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'USD');
        $this->assertSame(8.0, (float) $product->fresh()->current_stock);
        $this->assertSame(-300.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_eur_pesin_purchase_records_eur_everywhere_and_moves_stock(): void
    {
        $product = $this->product('EUR', stock: 5, price: 80);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'EUR');
        $this->assertSame(800.0, (float) $purchase->total);
        $this->assertSame(15.0, (float) $product->fresh()->current_stock);
        $this->assertSame(-800.0, CashTransaction::balanceForCurrency('EUR'));
    }

    public function test_tl_kismi_purchase_records_tl_everywhere(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('TL', stock: 0, price: 100);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'TL');
        $this->assertSame(600.0, $supplier->fresh()->balanceForCurrency('TL'));
        $this->assertSame(-400.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_usd_kismi_purchase_records_usd_everywhere(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('USD', stock: 0, price: 100);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'USD');
        $this->assertSame(600.0, $supplier->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, $supplier->fresh()->balanceForCurrency('TL'));
        $this->assertSame(-400.0, CashTransaction::balanceForCurrency('USD'));
    }

    public function test_eur_kismi_purchase_records_eur_everywhere(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('EUR', stock: 0, price: 80);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 300,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'EUR');
        $this->assertSame(500.0, $supplier->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(-300.0, CashTransaction::balanceForCurrency('EUR'));
    }

    public function test_tl_vadeli_purchase_records_tl_debt_and_creates_no_cash_movement(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('TL', stock: 0, price: 50);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'TL');
        $this->assertSame(200.0, $supplier->fresh()->balanceForCurrency('TL'));
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($purchase->cash_transaction_id);
    }

    public function test_usd_vadeli_purchase_records_usd_debt_and_creates_no_cash_movement(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('USD', stock: 0, price: 50);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'USD');
        $this->assertSame(200.0, $supplier->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($purchase->cash_transaction_id);
    }

    public function test_eur_vadeli_purchase_records_eur_debt_and_creates_no_cash_movement(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('EUR', stock: 0, price: 50);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ])->assertRedirect();

        $purchase = Purchase::first();
        $this->assertDocumentCurrency($purchase, 'EUR');
        $this->assertSame(200.0, $supplier->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($purchase->cash_transaction_id);
    }

    // --- Mixed-currency protection ---------------------------------------

    public function test_purchase_rejects_usd_and_eur_products_in_the_same_document(): void
    {
        $usd = $this->product('USD', stock: 0, price: 100);
        $eur = $this->product('EUR', stock: 0, price: 80);

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $usd->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $eur->id, 'quantity' => 1, 'unit_price' => 80],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Purchase::count());
    }

    public function test_purchase_rejects_usd_and_tl_products_in_the_same_document(): void
    {
        $usd = $this->product('USD', stock: 0, price: 100);
        $tl = $this->product('TL', stock: 0, price: 50);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $usd->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $tl->id, 'quantity' => 1, 'unit_price' => 50],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(0, Purchase::count());
    }

    public function test_purchase_rejects_eur_and_tl_products_in_the_same_document(): void
    {
        $eur = $this->product('EUR', stock: 0, price: 80);
        $tl = $this->product('TL', stock: 0, price: 50);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $eur->id, 'quantity' => 1, 'unit_price' => 80],
                ['product_id' => $tl->id, 'quantity' => 1, 'unit_price' => 50],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(0, Purchase::count());
    }

    public function test_rejected_mixed_currency_purchase_leaves_no_partial_records_of_any_kind(): void
    {
        $usd = $this->product('USD', stock: 0, price: 100);
        $eur = $this->product('EUR', stock: 0, price: 80);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $usd->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $eur->id, 'quantity' => 1, 'unit_price' => 80],
            ],
        ]);

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(0, CashTransaction::count());
        $this->assertSame(0.0, (float) $usd->fresh()->current_stock);
        $this->assertSame(0.0, (float) $eur->fresh()->current_stock);
    }

    // --- Reversal preserves currency --------------------------------------

    public function test_cancelling_a_usd_purchase_reverses_stock_debt_and_cash_all_in_usd(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('USD', stock: 0, price: 100);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $purchase = Purchase::first();
        $this->actingAs($this->admin())->post("/purchases/{$purchase->id}/cancel")->assertRedirect();

        $this->assertSame(0.0, $supplier->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);

        foreach (AccountTransaction::where('account_id', $supplier->id)->get() as $transaction) {
            $this->assertSame('USD', $transaction->currency);
        }
        foreach (CashTransaction::all() as $transaction) {
            $this->assertSame('USD', $transaction->currency);
        }
    }

    public function test_cancelling_a_eur_purchase_reverses_stock_debt_and_cash_all_in_eur(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product('EUR', stock: 0, price: 80);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ]);

        $purchase = Purchase::first();
        $this->actingAs($this->admin())->post("/purchases/{$purchase->id}/cancel")->assertRedirect();

        $this->assertSame(0.0, $supplier->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('EUR'));
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }
}
