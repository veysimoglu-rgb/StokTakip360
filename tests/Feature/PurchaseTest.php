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

class PurchaseTest extends TestCase
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

    private function product(int $stock = 10, float $price = 50): Product
    {
        return Product::factory()->create(['current_stock' => $stock, 'purchase_price' => $price, 'currency' => 'TL']);
    }

    // 1. Peşin alış, cari seçilmeden
    public function test_cash_purchase_without_account(): void
    {
        $product = $this->product(stock: 5);

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $purchase = Purchase::first();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame(15.0, (float) $product->fresh()->current_stock);
        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(-500.0, CashTransaction::balance());
        $this->assertSame(Purchase::class, StockMovement::first()->source_type);
    }

    // 2. Peşin alış, cari seçili — net borç sıfır
    public function test_cash_purchase_with_account_nets_to_zero_debt(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ]);

        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(-200.0, CashTransaction::balance());
    }

    // 3. Vadeli alış
    public function test_credit_purchase_creates_debt_with_no_cash_movement(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 6, 'unit_price' => 50]],
        ]);

        $this->assertSame(300.0, $supplier->fresh()->balance());
        $this->assertSame(0.0, CashTransaction::balance());
        $purchase = Purchase::first();
        $this->assertSame('unpaid', $purchase->status);
    }

    /**
     * Regression guard: purchases/create.blade.php's hidden field always
     * submits paid_amount=0 for a vadeli purchase. Before the fix, the
     * controller's `min:0.01` rule rejected that "0" and the whole request
     * failed validation — this reproduces the exact browser payload.
     */
    public function test_credit_purchase_succeeds_with_the_real_browser_payload_of_paid_amount_zero(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0, price: 50);

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'paid_amount' => 0,
            'discount_total' => 0,
            'purchase_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'note' => '',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $purchase = Purchase::first();
        $this->assertNotNull($purchase);
        $this->assertSame('unpaid', $purchase->status);
        $this->assertSame(0.0, (float) $purchase->paid_amount);

        // Cari borç hareketi oluşmalı.
        $this->assertSame(150.0, $supplier->fresh()->balance());
        $this->assertNotNull($purchase->debt_account_transaction_id);

        // Vadeli işlemde kasa hareketi oluşmamalı.
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($purchase->cash_transaction_id);
        $this->assertNull($purchase->payment_account_transaction_id);

        // Stok doğru değişmeli (alış -> artış).
        $this->assertSame(3.0, (float) $product->fresh()->current_stock);
    }

    public function test_credit_purchase_with_zero_paid_amount_still_rolls_back_on_invalid_discount(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0, price: 50);

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'paid_amount' => 0,
            'discount_total' => 999999,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }

    /**
     * Regression guard for the invisible-error bug: after a failed vadeli
     * submission, the re-rendered create form must echo payment_type back
     * into Alpine's initial state instead of silently resetting to "pesin".
     */
    public function test_create_form_remembers_the_chosen_payment_type_after_a_validation_error(): void
    {
        $response = $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'vadeli',
            'paid_amount' => 0,
            // no account_id and no items -> guaranteed validation failure
        ]);

        $response->assertSessionHasErrors();

        $this->actingAs($this->admin())
            ->get('/purchases/create')
            ->assertSee("paymentType: 'vadeli'", false);
    }

    /**
     * Regression guard for the real root cause: Chrome refused to submit the
     * form at all (client-side HTML5 constraint validation on a hidden-but-
     * still-in-DOM min="0.01" field). This inspects the actual response
     * markup: the min="0.01" input must live inside the kismi-only
     * <template x-if>, never inside the old x-show wrapper.
     */
    public function test_paid_amount_min_constraint_only_exists_inside_the_kismi_only_template(): void
    {
        $content = $this->actingAs($this->admin())->get('/purchases/create')->getContent();

        $this->assertStringNotContainsString("x-show=\"paymentType === 'kismi'\"", $content);
        $this->assertStringContainsString('<template x-if="paymentType === \'kismi\'">', $content);
        $this->assertSame(1, substr_count($content, 'min="0.01"'));
        $this->assertStringContainsString('type="hidden" name="paid_amount"', $content);

        $kismiTemplateStart = strpos($content, '<template x-if="paymentType === \'kismi\'">');
        $minAttributePosition = strpos($content, 'min="0.01"');
        $templateEnd = strpos($content, '</template>', $kismiTemplateStart);

        $this->assertNotFalse($kismiTemplateStart);
        $this->assertNotFalse($minAttributePosition);
        $this->assertGreaterThan($kismiTemplateStart, $minAttributePosition);
        $this->assertLessThan($templateEnd, $minAttributePosition);
    }

    // 4. Kısmi ödemeli alış (spesifikasyon örneği: 20.000 borç, 5.000 ödeme -> 15.000 kalan, kasa -5.000)
    public function test_partial_payment_purchase_matches_specification_example(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0, price: 2000);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 50000]);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 5000,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 2000]],
        ]);

        $purchase = Purchase::first();
        $this->assertSame(20000.0, (float) $purchase->total);
        $this->assertSame(15000.0, $supplier->fresh()->balance());
        $this->assertSame(45000.0, CashTransaction::balance());
    }

    // 5/6. Cari zorunluluğu
    public function test_credit_purchase_without_account_fails_validation(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'vadeli',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Purchase::count());
    }

    // 8. Çok ürünlü alış
    public function test_multi_item_purchase_creates_one_stock_movement_per_item(): void
    {
        $productA = $this->product(stock: 0, price: 10);
        $productB = $this->product(stock: 0, price: 20);

        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 5, 'unit_price' => 10],
                ['product_id' => $productB->id, 'quantity' => 3, 'unit_price' => 20],
            ],
        ]);

        $purchase = Purchase::first();
        $this->assertSame(2, $purchase->items()->count());
        $this->assertSame(110.0, (float) $purchase->total);
        $this->assertSame(5.0, (float) $productA->fresh()->current_stock);
        $this->assertSame(3.0, (float) $productB->fresh()->current_stock);
    }

    public function test_discount_greater_than_subtotal_is_rejected(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'discount_total' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Purchase::count());
    }

    // 13. Alış iptali: stok + cari + kasa atomik geri alınır
    public function test_cancelling_a_partial_purchase_reverses_stock_account_and_cash(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0, price: 2000);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 50000]);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 5000,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 2000]],
        ]);
        $purchase = Purchase::first();

        $response = $this->actingAs($this->admin())->post("/purchases/{$purchase->id}/cancel");

        $response->assertRedirect();
        $this->assertTrue($purchase->fresh()->isCancelled());
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(50000.0, CashTransaction::balance());
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }

    // Alış iptalinde, o alıştan gelen stok zaten tükenmişse iptal engellenir
    public function test_cancelling_a_purchase_is_blocked_when_its_stock_has_already_been_sold(): void
    {
        $product = $this->product(stock: 0, price: 50);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();

        // All 10 units just arrived are now sold onward, leaving nothing to reverse.
        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80]],
        ]);

        $response = $this->actingAs($admin)->post("/purchases/{$purchase->id}/cancel");

        $response->assertSessionHas('error');
        $this->assertFalse($purchase->fresh()->isCancelled());
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }

    // 14. Çift iptal engeli
    public function test_a_purchase_cannot_be_cancelled_twice(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();

        $this->actingAs($this->admin())->post("/purchases/{$purchase->id}/cancel");
        $response = $this->actingAs($this->admin())->post("/purchases/{$purchase->id}/cancel");

        $response->assertSessionHas('error');
    }

    // Çapraz bağlantılar üzerinden iptal
    public function test_cancelling_via_the_account_transaction_route_cascades_the_whole_purchase(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0, price: 50);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();

        $response = $this->actingAs($this->admin())->post('/account-transactions/'.$purchase->debtAccountTransaction->id.'/cancel');

        $response->assertRedirect();
        $this->assertTrue($purchase->fresh()->isCancelled());
        $this->assertSame(0.0, $supplier->fresh()->balance());
        $this->assertSame(0.0, (float) $product->fresh()->current_stock);
    }

    public function test_purchase_rejects_a_customer_type_account(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $customer->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Purchase::count());
    }

    // Yetkisiz erişim
    public function test_personel_cannot_create_a_purchase(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->personel())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Purchase::count());
    }

    public function test_personel_cannot_cancel_a_purchase(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();

        $response = $this->actingAs($this->personel())->post("/purchases/{$purchase->id}/cancel");

        $response->assertForbidden();
        $this->assertFalse($purchase->fresh()->isCancelled());
    }

    public function test_personel_can_view_purchases_list_and_detail(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();

        $this->actingAs($this->personel())->get('/purchases')->assertOk();
        $this->actingAs($this->personel())->get("/purchases/{$purchase->id}")->assertOk();
    }

    public function test_guest_is_redirected_from_purchases(): void
    {
        $this->get('/purchases')->assertRedirect('/login');
        $this->get('/purchases/create')->assertRedirect('/login');
    }

    public function test_admin_can_view_the_purchase_create_form(): void
    {
        $this->actingAs($this->admin())->get('/purchases/create')->assertOk();
    }
}
