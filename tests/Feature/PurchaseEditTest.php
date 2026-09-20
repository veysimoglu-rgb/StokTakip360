<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function product(float $stock = 0, float $price = 10, string $currency = 'TL'): Product
    {
        return Product::factory()->create(['current_stock' => $stock, 'purchase_price' => $price, 'sale_price' => $price * 2, 'currency' => $currency]);
    }

    private function stock(Product $product): float
    {
        return (float) $product->fresh()->current_stock;
    }

    /** in − out over every movement of the product (reversals included). */
    private function netIn(Product $product): float
    {
        $rows = StockMovement::where('product_id', $product->id);

        return (float) (clone $rows)->where('type', 'in')->sum('quantity') - (float) (clone $rows)->where('type', 'out')->sum('quantity');
    }

    private function buy(?Account $supplier, Product $product, int $qty, float $price = 10, string $payment = 'vadeli', array $extra = []): Purchase
    {
        $this->actingAs($this->admin)->post('/purchases', $extra + array_filter([
            'account_id' => $supplier?->id,
            'payment_type' => $payment,
            'due_date' => $payment === 'pesin' ? null : now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $price]],
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors()->assertSessionMissing('error');

        return Purchase::latest('id')->first();
    }

    private function payload(?Account $supplier, array $items, string $payment = 'vadeli', array $extra = []): array
    {
        return $extra + array_filter([
            'account_id' => $supplier?->id,
            'payment_type' => $payment,
            'due_date' => $payment === 'pesin' ? null : now()->addDays(30)->toDateString(),
            'items' => $items,
        ], fn ($v) => $v !== null);
    }

    private function line(Product $product, int $qty, float $price = 10): array
    {
        return ['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $price];
    }

    private function update(Purchase $purchase, array $payload, ?string $version = null)
    {
        return $this->actingAs($this->admin)->put("/purchases/{$purchase->id}", $payload + [
            '_version' => $version ?? $purchase->fresh()->versionToken(),
        ]);
    }

    private function supplier(): Account
    {
        return Account::factory()->create(['type' => 'supplier']);
    }

    // ---------------------------------------------------------------- quantities / prices

    public function test_increasing_the_quantity_only_reflects_the_difference_and_creates_no_duplicate_purchase(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(100);
        $purchase = $this->buy($supplier, $product, 10);
        $number = $purchase->number;
        $date = $purchase->purchase_date->toDateTimeString();
        $this->assertSame(110.0, $this->stock($product));
        $this->assertSame(100.0, $supplier->fresh()->balance());

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 15)]))
            ->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect(route('purchases.show', $purchase));

        $this->assertSame(1, Purchase::count());
        $purchase->refresh();
        $this->assertSame($number, $purchase->number);
        $this->assertSame($date, $purchase->purchase_date->toDateTimeString());
        $this->assertNotNull($purchase->edited_at);
        $this->assertSame(150.0, (float) $purchase->total);
        $this->assertSame(1, $purchase->items()->count());

        $this->assertSame(115.0, $this->stock($product));
        $this->assertSame(15.0, $this->netIn($product));
        $this->assertSame(150.0, $supplier->fresh()->balance());
        // ledger: original in + new in + reversal out
        $this->assertSame(3, StockMovement::count());
        // cari: original debt (cancelled) + its reversal + new debt
        $this->assertSame(3, AccountTransaction::count());
    }

    public function test_decreasing_the_quantity_takes_back_only_the_difference(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(100);
        $purchase = $this->buy($supplier, $product, 10);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 4)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(104.0, $this->stock($product));
        $this->assertSame(4.0, $this->netIn($product));
        $this->assertSame(40.0, $supplier->fresh()->balance());
        $this->assertSame(40.0, (float) $purchase->fresh()->total);
    }

    public function test_changing_only_the_unit_price_keeps_stock_and_updates_the_cari_amount(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10, 10);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 10, 12.5)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(10.0, $this->stock($product));
        $this->assertSame(125.0, (float) $purchase->fresh()->total);
        $this->assertSame(125.0, $supplier->fresh()->balance());
        $this->assertSame(12.5, (float) $purchase->fresh()->items()->first()->unit_price);
    }

    public function test_adding_removing_and_replacing_products(): void
    {
        $supplier = $this->supplier();
        $a = $this->product(0, 10);
        $b = $this->product(0, 20);
        $purchase = $this->buy($supplier, $a, 5, 10);

        // A çıkar, B ekle
        $this->update($purchase, $this->payload($supplier, [$this->line($b, 3, 20)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(0.0, $this->stock($a));
        $this->assertSame(3.0, $this->stock($b));
        $this->assertSame(60.0, $supplier->fresh()->balance());
        $this->assertSame(1, $purchase->fresh()->items()->count());

        // ikisi birden
        $this->update($purchase, $this->payload($supplier, [$this->line($a, 2, 10), $this->line($b, 1, 20)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(2.0, $this->stock($a));
        $this->assertSame(1.0, $this->stock($b));
        $this->assertSame(40.0, $supplier->fresh()->balance());
        $this->assertSame(2, $purchase->fresh()->items()->count());

        // yalnızca birini bırak
        $this->update($purchase, $this->payload($supplier, [$this->line($b, 1, 20)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(0.0, $this->stock($a));
        $this->assertSame(20.0, $supplier->fresh()->balance());
    }

    public function test_editing_repeatedly_never_duplicates_the_net_effect(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(50);
        $purchase = $this->buy($supplier, $product, 10);

        foreach ([12, 7, 10] as $qty) {
            $this->update($purchase, $this->payload($supplier, [$this->line($product, $qty)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        }

        $this->assertSame(1, Purchase::count());
        $this->assertSame(60.0, $this->stock($product));
        $this->assertSame(10.0, $this->netIn($product));
        $this->assertSame(100.0, $supplier->fresh()->balance());
    }

    public function test_the_same_product_on_two_lines_is_summed(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 6), $this->line($product, 9)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(15.0, $this->stock($product));
        $this->assertSame(150.0, $supplier->fresh()->balance());
    }

    // ---------------------------------------------------------------- stock already used (reverse-guard)

    private function sell(Product $product, int $qty): void
    {
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 30]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    public function test_reducing_down_to_exactly_the_used_quantity_is_allowed_but_one_less_is_not(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100);
        $this->sell($product, 60);
        $this->assertSame(40.0, $this->stock($product));

        $movementsBefore = StockMovement::count();
        $transactionsBefore = AccountTransaction::count();

        // 59 < 60 kullanılmış -> reddedilir, hiçbir şey değişmez
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 59)]))
            ->assertSessionHas('error');
        $this->assertStringContainsString('en az 60', session('error'));
        $this->assertSame(40.0, $this->stock($product));
        $this->assertSame($movementsBefore, StockMovement::count());
        $this->assertSame($transactionsBefore, AccountTransaction::count());
        $this->assertSame(1000.0, (float) $purchase->fresh()->total);
        $this->assertNull($purchase->fresh()->edited_at);

        // tam 60 -> geçer, stok tam 0
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 60)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(0.0, $this->stock($product));
        $this->assertSame(600.0, $supplier->fresh()->balance());
    }

    public function test_a_line_whose_stock_was_used_cannot_be_removed_but_an_untouched_one_can(): void
    {
        $supplier = $this->supplier();
        $sold = $this->product(0);
        $untouched = $this->product(0);
        $this->actingAs($this->admin)->post('/purchases', [
            'account_id' => $supplier->id, 'payment_type' => 'vadeli', 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [$this->line($sold, 10), $this->line($untouched, 5)],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $purchase = Purchase::first();
        $this->sell($sold, 8);

        $this->update($purchase, $this->payload($supplier, [$this->line($untouched, 5)]))->assertSessionHas('error');
        $this->assertSame(2, $purchase->fresh()->items()->count());

        $this->update($purchase, $this->payload($supplier, [$this->line($sold, 10)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(0.0, $this->stock($untouched));
        $this->assertSame(2.0, $this->stock($sold));
    }

    public function test_increasing_is_always_allowed_even_when_stock_was_used(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $this->sell($product, 10);
        $this->assertSame(0.0, $this->stock($product));

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 25)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(15.0, $this->stock($product));
    }

    public function test_the_edit_form_hints_the_minimum_quantity_for_used_stock(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100);
        $this->sell($product, 60);

        $this->assertSame([$product->id => 60], app(PurchaseService::class)->minimumQuantities($purchase->fresh()));

        $content = $this->actingAs($this->admin)->get("/purchases/{$purchase->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('minQty(item)', $content);
        $this->assertStringContainsString('mins:', $content);
    }

    // ---------------------------------------------------------------- payments / kasa

    public function test_cash_purchase_edit_replaces_the_cash_movement(): void
    {
        $product = $this->product(0);
        $purchase = $this->buy(null, $product, 5, 10, 'pesin');
        $this->assertSame(-50.0, CashTransaction::balance());

        $this->update($purchase, $this->payload(null, [$this->line($product, 8)], 'pesin'))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(-80.0, CashTransaction::balance());
        $this->assertSame('paid', $purchase->status);
        $this->assertSame(80.0, (float) $purchase->paid_amount);
        $this->assertSame(8.0, $this->stock($product));
        $this->assertSame(0, AccountTransaction::count());
    }

    public function test_partial_payment_purchase_edit_keeps_the_ledger_balanced(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100, 10, 'kismi', ['paid_amount' => 400]);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 120)], 'kismi', ['paid_amount' => 500]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(1200.0, (float) $purchase->total);
        $this->assertSame(500.0, (float) $purchase->paid_amount);
        $this->assertSame('partial', $purchase->status);
        $this->assertSame(700.0, $supplier->fresh()->balance());
        $this->assertSame(-500.0, CashTransaction::balance());
        $this->assertNotNull($purchase->debt_account_transaction_id);
        $this->assertNotNull($purchase->payment_account_transaction_id);
        $this->assertNotNull($purchase->cash_transaction_id);
    }

    public function test_switching_payment_type_between_vadeli_and_pesin(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 10)], 'pesin'))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $purchase->refresh();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(-100.0, CashTransaction::balance());
        $this->assertNotNull($purchase->payment_account_transaction_id);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 10)], 'vadeli'))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $purchase->refresh();
        $this->assertSame('unpaid', $purchase->status);
        $this->assertSame(100.0, $supplier->fresh()->balance());
        $this->assertSame(0.0, CashTransaction::balance());
        $this->assertNull($purchase->payment_account_transaction_id);
        $this->assertNull($purchase->cash_transaction_id);
    }

    public function test_a_later_payment_applied_to_the_purchase_survives_an_edit(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100);

        $this->actingAs($this->admin)->post("/accounts/{$supplier->id}/pay", ['amount' => 400, 'currency' => 'TL', 'purchase_id' => $purchase->id])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(400.0, (float) $purchase->fresh()->paid_amount);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 150)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $purchase->refresh();
        $this->assertSame(1500.0, (float) $purchase->total);
        $this->assertSame(400.0, (float) $purchase->paid_amount);
        $this->assertSame('partial', $purchase->status);
        $this->assertSame(1100.0, $supplier->fresh()->balance());
        $this->assertSame(-400.0, CashTransaction::balance());
    }

    public function test_cancelling_the_later_payment_after_an_edit_still_gives_the_amount_back(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100);
        $this->actingAs($this->admin)->post("/accounts/{$supplier->id}/pay", ['amount' => 400, 'currency' => 'TL', 'purchase_id' => $purchase->id]);
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 100, 12)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $payment = CashTransaction::where('type', 'payment')->whereNull('reversal_of_id')->whereNull('cancelled_at')->first();
        $this->actingAs($this->admin)->post("/cash-transactions/{$payment->id}/cancel")->assertRedirect();

        $purchase->refresh();
        $this->assertSame(0.0, (float) $purchase->paid_amount);
        $this->assertSame('unpaid', $purchase->status);
        $this->assertSame(1200.0, $supplier->fresh()->balance());
    }

    public function test_edit_is_refused_when_later_payments_would_exceed_the_new_total(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100);
        $this->actingAs($this->admin)->post("/accounts/{$supplier->id}/pay", ['amount' => 800, 'currency' => 'TL', 'purchase_id' => $purchase->id]);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 50)]))->assertSessionHas('error');

        $this->assertSame(1000.0, (float) $purchase->fresh()->total);
        $this->assertSame(100.0, $this->stock($product));
        $this->assertSame(200.0, $supplier->fresh()->balance());
    }

    public function test_supplier_and_currency_cannot_change_once_a_later_payment_exists(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier();
        $tl = $this->product(0);
        $usd = $this->product(0, 10, 'USD');
        $purchase = $this->buy($supplier, $tl, 100);
        $this->actingAs($this->admin)->post("/accounts/{$supplier->id}/pay", ['amount' => 300, 'currency' => 'TL', 'purchase_id' => $purchase->id]);

        $this->update($purchase, $this->payload($other, [$this->line($tl, 100)]))->assertSessionHas('error');
        $this->assertStringContainsString('tedarikçi değiştirilemez', session('error'));

        $this->update($purchase, $this->payload($supplier, [$this->line($usd, 100)]))->assertSessionHas('error');
        $this->assertStringContainsString('para birimi değiştirilemez', session('error'));

        $this->assertSame($supplier->id, $purchase->fresh()->account_id);
        $this->assertSame('TL', $purchase->fresh()->currency);
        $this->assertSame(0.0, $this->stock($usd));
        $this->assertSame(700.0, $supplier->fresh()->balance());
        $this->assertSame(0.0, $other->fresh()->balance());
    }

    public function test_supplier_can_change_when_there_is_no_later_payment(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);

        $this->update($purchase, $this->payload($other, [$this->line($product, 10)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame($other->id, $purchase->fresh()->account_id);
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(100.0, $other->fresh()->balance());
    }

    public function test_usd_purchase_edit_keeps_every_leg_in_usd(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0, 10, 'USD');
        $purchase = $this->buy($supplier, $product, 10, 10, 'pesin');
        $this->assertSame('USD', $purchase->currency);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 12)], 'pesin'))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(-120.0, CashTransaction::balanceForCurrency('USD'));
        $this->assertSame(0.0, CashTransaction::balanceForCurrency('TL'));
        $this->assertSame(0.0, $supplier->fresh()->balanceForCurrency('USD'));
        $this->assertSame(12.0, $this->stock($product));
    }

    public function test_mixed_currencies_are_rejected_on_edit_without_side_effects(): void
    {
        $supplier = $this->supplier();
        $tl = $this->product(0);
        $usd = $this->product(0, 10, 'USD');
        $purchase = $this->buy($supplier, $tl, 10);
        $movements = StockMovement::count();

        $this->update($purchase, $this->payload($supplier, [$this->line($tl, 5), $this->line($usd, 5)]))->assertSessionHas('error');

        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(10.0, $this->stock($tl));
        $this->assertSame(0.0, $this->stock($usd));
    }

    // ---------------------------------------------------------------- identity, guards, concurrency

    public function test_a_cancelled_purchase_cannot_be_edited(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/cancel");

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}/edit")->assertRedirect(route('purchases.show', $purchase));
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 20)]))->assertSessionHas('error');

        $this->assertSame(0.0, $this->stock($product));
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertNull($purchase->fresh()->edited_at);
    }

    public function test_only_admins_can_open_or_save_an_edit(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $personel = User::factory()->create();
        $personel->assignRole('Personel');

        $this->actingAs($personel)->get("/purchases/{$purchase->id}/edit")->assertForbidden();
        $this->actingAs($personel)->put("/purchases/{$purchase->id}", $this->payload($supplier, [$this->line($product, 99)]) + ['_version' => $purchase->versionToken()])->assertForbidden();
        $this->assertSame(100.0, (float) $purchase->fresh()->total);
        $this->assertSame(10.0, $this->stock($product));
    }

    public function test_a_stale_form_never_overwrites_a_newer_change(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $opened = $purchase->fresh()->versionToken();

        // a second admin saves first
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 20)]), $opened)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertNotSame($opened, $purchase->fresh()->versionToken());

        // the first form is now stale: nothing is written, the user is sent back to the fresh edit page
        $response = $this->update($purchase, $this->payload($supplier, [$this->line($product, 5)]), $opened);

        $response->assertRedirect(route('purchases.edit', $purchase));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('başka bir kullanıcı', session('error'));
        $this->assertSame(200.0, (float) $purchase->fresh()->total);
        $this->assertSame(20.0, $this->stock($product));
        $this->assertSame(200.0, $supplier->fresh()->balance());
    }

    public function test_a_payment_made_after_opening_the_form_makes_it_stale(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $opened = $purchase->fresh()->versionToken();

        $this->actingAs($this->admin)->post("/accounts/{$supplier->id}/pay", ['amount' => 30, 'currency' => 'TL', 'purchase_id' => $purchase->id]);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 5)]), $opened)
            ->assertRedirect(route('purchases.edit', $purchase))->assertSessionHas('error');
        $this->assertSame(100.0, (float) $purchase->fresh()->total);
        $this->assertSame(10.0, $this->stock($product));
    }

    public function test_the_version_is_required_and_the_form_carries_it(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);

        $this->actingAs($this->admin)->put("/purchases/{$purchase->id}", $this->payload($supplier, [$this->line($product, 5)]))
            ->assertSessionHasErrors('_version');
        $this->assertSame(100.0, (float) $purchase->fresh()->total);

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}/edit")->assertOk()
            ->assertSee('name="_version" value="'.$purchase->versionToken().'"', false)
            ->assertSee('name="_method" value="PUT"', false);
    }

    public function test_a_failure_after_the_new_rows_are_written_rolls_everything_back(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(100);
        $purchase = $this->buy($supplier, $product, 10, 10, 'kismi', ['paid_amount' => 40]);

        $counts = fn () => [
            StockMovement::count(), AccountTransaction::count(), CashTransaction::count(),
            (float) $product->fresh()->current_stock, (float) $purchase->fresh()->total, $purchase->fresh()->debt_account_transaction_id,
        ];
        $before = $counts();

        // A supplier id that does not exist: the failure is raised late in the transaction.
        $data = $this->payload($supplier, [$this->line($product, 20)], 'kismi', ['paid_amount' => 50, 'account_id' => 999999]);

        try {
            app(PurchaseService::class)->update($purchase, $data, $this->admin);
            $this->fail('the edit should have failed');
        } catch (QueryException $e) {
            // A supplier id that does not exist violates the purchases.account_id foreign key when
            // the header is saved — after the new stock rows were created and the old stock/cari/kasa
            // rows were reversed — so a clean state below proves the rollback.
            $this->assertStringContainsString('FOREIGN KEY', strtoupper($e->getMessage()));
        }

        $this->assertSame($before, $counts());
        $this->assertNull($purchase->fresh()->edited_at);
        $this->assertSame(60.0, $supplier->fresh()->balance());
    }

    public function test_an_edited_purchase_can_still_be_cancelled_and_fully_reverses(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(100);
        $purchase = $this->buy($supplier, $product, 10, 10, 'kismi', ['paid_amount' => 40]);
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 15)], 'kismi', ['paid_amount' => 50]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/cancel")->assertRedirect();

        $this->assertTrue($purchase->fresh()->isCancelled());
        $this->assertSame(100.0, $this->stock($product));
        $this->assertSame(0.0, $this->netIn($product));
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(0.0, CashTransaction::balance());
    }

    public function test_cancelling_through_the_account_transaction_route_works_after_an_edit(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 12)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $debt = $purchase->fresh()->debtAccountTransaction;
        $this->actingAs($this->admin)->post("/account-transactions/{$debt->id}/cancel")->assertRedirect();

        $this->assertTrue($purchase->fresh()->isCancelled());
        $this->assertSame(0.0, $this->stock($product));
        $this->assertSame(0.0, $supplier->fresh()->balance());
    }

    // ---------------------------------------------------------------- UI

    public function test_the_edit_form_is_prefilled_and_shows_the_original_date_read_only(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}/edit")->assertOk()
            ->assertSee('Alışı Güncelle')
            ->assertSee($purchase->number)
            ->assertSee($purchase->purchase_date->format('d.m.Y'))
            ->assertSee("paymentType: 'vadeli'", false)
            ->assertDontSee('name="purchase_date"', false);
    }

    public function test_a_partial_payment_purchase_prefills_only_its_own_payment_not_later_ones(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100, 10, 'kismi', ['paid_amount' => 300]);
        $this->actingAs($this->admin)->post("/accounts/{$supplier->id}/pay", ['amount' => 200, 'currency' => 'TL', 'purchase_id' => $purchase->id]);
        $this->assertSame(500.0, (float) $purchase->fresh()->paid_amount);

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}/edit")->assertOk()
            ->assertSee('paidAmount: 300', false);
    }

    public function test_show_and_list_offer_edit_only_for_active_purchases_and_flag_edited_ones(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $editUrl = route('purchases.edit', $purchase);

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}")->assertOk()->assertSee($editUrl, false)->assertDontSee('Düzenlendi');
        $this->actingAs($this->admin)->get('/purchases')->assertOk()->assertSee($editUrl, false);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 11)]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}")->assertOk()->assertSee('Düzenlendi');

        $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/cancel");
        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}")->assertOk()->assertDontSee($editUrl, false);
        $this->actingAs($this->admin)->get('/purchases')->assertOk()->assertDontSee($editUrl, false);
    }

    public function test_a_failed_edit_returns_to_the_form_keeping_the_typed_rows(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 100);
        $this->sell($product, 60);

        $this->update($purchase, $this->payload($supplier, [$this->line($product, 50, 11)]))
            ->assertSessionHas('error')->assertSessionHasInput('items.0.quantity', 50);
    }

    public function test_reports_and_dashboard_read_the_edited_totals(): void
    {
        $supplier = $this->supplier();
        $product = $this->product(0);
        $purchase = $this->buy($supplier, $product, 10);
        $this->update($purchase, $this->payload($supplier, [$this->line($product, 30)]))->assertSessionHasNoErrors()->assertSessionMissing('error');

        $response = $this->actingAs($this->admin)->get('/reports/purchases')->assertOk();
        $this->assertSame(1, $response->viewData('purchases')->count());
        $this->assertSame(300.0, (float) Purchase::sum('total'));
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $this->assertSame(1, Purchase::where('id', $purchase->id)->whereNull('cancelled_at')->count());
    }
}
