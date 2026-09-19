<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleCurrencyTest extends TestCase
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

    private function product(string $currency, int $stock = 100, float $price = 100): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'sale_price' => $price,
            'currency' => $currency,
        ]);
    }

    private function assertDocumentCurrency(Sale $sale, string $currency): void
    {
        $this->assertSame($currency, $sale->currency);
        $this->assertSame($currency, StockMovement::where('source_type', Sale::class)->where('source_id', $sale->id)->first()->currency);

        if ($sale->debtAccountTransaction) {
            $this->assertSame($currency, $sale->debtAccountTransaction->currency);
        }

        if ($sale->paymentAccountTransaction) {
            $this->assertSame($currency, $sale->paymentAccountTransaction->currency);
        }

        if ($sale->cashTransaction) {
            $this->assertSame($currency, $sale->cashTransaction->currency);
        }
    }

    // --- TL/USD/EUR x peşin/kısmi/vadeli --------------------------------

    public function test_tl_pesin_sale_records_tl_everywhere_and_moves_stock(): void
    {
        $product = $this->product('TL', stock: 20, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'TL');
        $this->assertSame('paid', $sale->status);
        $this->assertSame(300.0, (float) $sale->total);
        $this->assertSame(17.0, (float) $product->fresh()->current_stock);
        $this->assertSame(300.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_usd_pesin_sale_records_usd_everywhere_and_moves_stock(): void
    {
        $product = $this->product('USD', stock: 20, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'USD');
        $this->assertSame(300.0, (float) $sale->total);
        $this->assertSame(17.0, (float) $product->fresh()->current_stock);
        $this->assertSame(300.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_eur_pesin_sale_records_eur_everywhere_and_moves_stock(): void
    {
        $product = $this->product('EUR', stock: 20, price: 80);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'EUR');
        $this->assertSame(800.0, (float) $sale->total);
        $this->assertSame(10.0, (float) $product->fresh()->current_stock);
        $this->assertSame(800.0, CashTransaction::balanceForCurrency('EUR'));
    }

    public function test_tl_kismi_sale_records_tl_everywhere(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('TL', stock: 50, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'TL');
        $this->assertSame(600.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(400.0, CashTransaction::balanceForCurrency('TL'));
    }

    public function test_usd_kismi_sale_records_usd_everywhere(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('USD', stock: 50, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'USD');
        $this->assertSame(600.0, $customer->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(400.0, CashTransaction::balanceForCurrency('USD'));
    }

    public function test_eur_kismi_sale_records_eur_everywhere(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('EUR', stock: 50, price: 80);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 300,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'EUR');
        $this->assertSame(500.0, $customer->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(300.0, CashTransaction::balanceForCurrency('EUR'));
    }

    public function test_tl_vadeli_sale_records_tl_debt_and_creates_no_cash_movement(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('TL', stock: 50, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'TL');
        $this->assertSame(200.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($sale->cash_transaction_id);
    }

    public function test_usd_vadeli_sale_records_usd_debt_and_creates_no_cash_movement(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('USD', stock: 50, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'USD');
        $this->assertSame(200.0, $customer->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('TL'));
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($sale->cash_transaction_id);
    }

    public function test_eur_vadeli_sale_records_eur_debt_and_creates_no_cash_movement(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('EUR', stock: 50, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ])->assertRedirect();

        $sale = Sale::first();
        $this->assertDocumentCurrency($sale, 'EUR');
        $this->assertSame(200.0, $customer->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($sale->cash_transaction_id);
    }

    // --- Mixed-currency protection ---------------------------------------

    public function test_sale_rejects_usd_and_eur_products_in_the_same_document(): void
    {
        $usd = $this->product('USD', stock: 10, price: 100);
        $eur = $this->product('EUR', stock: 10, price: 80);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $usd->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $eur->id, 'quantity' => 1, 'unit_price' => 80],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, Sale::count());
    }

    public function test_sale_rejects_usd_and_tl_products_in_the_same_document(): void
    {
        $usd = $this->product('USD', stock: 10, price: 100);
        $tl = $this->product('TL', stock: 10, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $usd->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $tl->id, 'quantity' => 1, 'unit_price' => 50],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(0, Sale::count());
    }

    public function test_sale_rejects_eur_and_tl_products_in_the_same_document(): void
    {
        $eur = $this->product('EUR', stock: 10, price: 80);
        $tl = $this->product('TL', stock: 10, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $eur->id, 'quantity' => 1, 'unit_price' => 80],
                ['product_id' => $tl->id, 'quantity' => 1, 'unit_price' => 50],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(0, Sale::count());
    }

    public function test_rejected_mixed_currency_sale_leaves_no_partial_records_of_any_kind(): void
    {
        $usd = $this->product('USD', stock: 10, price: 100);
        $eur = $this->product('EUR', stock: 10, price: 80);
        $stockBefore = [$usd->current_stock, $eur->current_stock];

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $usd->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $eur->id, 'quantity' => 1, 'unit_price' => 80],
            ],
        ]);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(0, CashTransaction::count());
        $this->assertSame((float) $stockBefore[0], (float) $usd->fresh()->current_stock);
        $this->assertSame((float) $stockBefore[1], (float) $eur->fresh()->current_stock);
    }

    // --- Reversal preserves currency --------------------------------------

    public function test_cancelling_a_usd_sale_reverses_stock_debt_and_cash_all_in_usd(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('USD', stock: 50, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $sale = Sale::first();
        $this->actingAs($this->admin())->post("/sales/{$sale->id}/cancel")->assertRedirect();

        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(50.0, (float) $product->fresh()->current_stock);

        foreach (AccountTransaction::where('account_id', $customer->id)->get() as $transaction) {
            $this->assertSame('USD', $transaction->currency);
        }
        foreach (CashTransaction::all() as $transaction) {
            $this->assertSame('USD', $transaction->currency);
        }
    }

    public function test_cancelling_a_eur_sale_reverses_stock_debt_and_cash_all_in_eur(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product('EUR', stock: 50, price: 80);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ]);

        $sale = Sale::first();
        $this->actingAs($this->admin())->post("/sales/{$sale->id}/cancel")->assertRedirect();

        $this->assertSame(0.0, $customer->fresh()->balanceForCurrency('EUR'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('EUR'));
        $this->assertSame(50.0, (float) $product->fresh()->current_stock);
    }
}
