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

class SaleTest extends TestCase
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
        return Product::factory()->create(['current_stock' => $stock, 'sale_price' => $price, 'currency' => 'TL']);
    }

    // 1. Peşin satış, cari seçilmeden (anonim nakit satış)
    public function test_cash_sale_without_account(): void
    {
        $product = $this->product(stock: 20);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $sale = Sale::first();
        $this->assertSame('paid', $sale->status);
        $this->assertSame(150.0, (float) $sale->total);
        $this->assertSame(17, $product->fresh()->current_stock);
        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(150.0, CashTransaction::balance());
        $this->assertSame(Sale::class, StockMovement::first()->source_type);
    }

    // 2. Peşin satış, cari seçili — cari üzerinde net etki sıfır olmalı
    public function test_cash_sale_with_account_nets_to_zero_debt(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]],
        ]);

        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(100.0, CashTransaction::balance());
        $this->assertSame(2, AccountTransaction::count());
    }

    // 3. Vadeli satış
    public function test_credit_sale_creates_debt_with_no_cash_movement(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertSame(200.0, $customer->fresh()->balance());
        $this->assertSame(0.0, CashTransaction::balance());
        $sale = Sale::first();
        $this->assertSame('unpaid', $sale->status);
        $this->assertNotNull($sale->debt_account_transaction_id);
        $this->assertNull($sale->payment_account_transaction_id);
        $this->assertNull($sale->cash_transaction_id);
    }

    /**
     * Regression guard: sales/create.blade.php's hidden field always submits
     * paid_amount=0 for a vadeli sale (it is not simply omitted). Before the
     * fix, the controller's `min:0.01` rule rejected that "0" and the whole
     * request failed validation — this reproduces the exact browser payload.
     */
    public function test_credit_sale_succeeds_with_the_real_browser_payload_of_paid_amount_zero(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(stock: 20, price: 50);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'paid_amount' => 0,
            'discount_total' => 0,
            'sale_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'note' => '',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame('unpaid', $sale->status);
        $this->assertSame(0.0, (float) $sale->paid_amount);

        // Cari borç hareketi oluşmalı.
        $this->assertSame(150.0, $customer->fresh()->balance());
        $this->assertNotNull($sale->debt_account_transaction_id);

        // Vadeli işlemde kasa hareketi oluşmamalı.
        $this->assertSame(0, CashTransaction::count());
        $this->assertNull($sale->cash_transaction_id);
        $this->assertNull($sale->payment_account_transaction_id);

        // Stok doğru değişmeli.
        $this->assertSame(17, $product->fresh()->current_stock);
    }

    /**
     * Same payload shape, but paired with a genuinely invalid discount so
     * the atomicity guarantee is re-checked under the relaxed min:0 rule:
     * paid_amount=0 alone must not bypass the other business rules.
     */
    public function test_credit_sale_with_zero_paid_amount_still_rolls_back_on_invalid_discount(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(stock: 20, price: 50);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'paid_amount' => 0,
            'discount_total' => 999999,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(20, $product->fresh()->current_stock);
    }

    /**
     * Regression guard for the invisible-error bug: after a failed vadeli
     * submission, the re-rendered create form must echo payment_type back
     * into Alpine's initial state — not silently reset to "pesin" — so the
     * radio button the user picked stays visibly selected.
     */
    public function test_create_form_remembers_the_chosen_payment_type_after_a_validation_error(): void
    {
        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'vadeli',
            'paid_amount' => 0,
            // no account_id and no items -> guaranteed validation failure
        ]);

        $response->assertSessionHasErrors();

        $this->actingAs($this->admin())
            ->get('/sales/create')
            ->assertSee("paymentType: 'vadeli'", false);
    }

    /**
     * Regression guard for the real root cause: Chrome refused to submit the
     * form at all (client-side HTML5 constraint validation on a hidden-but-
     * still-in-DOM min="0.01" field) — a bug no HTTP-level test could ever
     * catch, since PHPUnit never renders the page. This inspects the actual
     * response markup: the min="0.01" input must live inside the kismi-only
     * <template x-if>, never inside the old x-show wrapper (which leaves the
     * element in the DOM, still subject to native constraint validation,
     * even while visually hidden).
     */
    public function test_paid_amount_min_constraint_only_exists_inside_the_kismi_only_template(): void
    {
        $content = $this->actingAs($this->admin())->get('/sales/create')->getContent();

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

    // 4. Kısmi ödemeli satış (spesifikasyon örneği: 1000 satış / 400 ödeme -> 600 borç, kasa +400)
    public function test_partial_payment_sale_matches_specification_example(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(stock: 50, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $sale = Sale::first();
        $this->assertSame(1000.0, (float) $sale->total);
        $this->assertSame('partial', $sale->status);
        $this->assertSame(600.0, $customer->fresh()->balance());
        $this->assertSame(400.0, CashTransaction::balance());
    }

    // 5. Vadeli satışta cari seçilmezse hata
    public function test_credit_sale_without_account_fails_validation(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'vadeli',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Sale::count());
    }

    // Kısmi ödemede de cari zorunlu
    public function test_partial_sale_without_account_fails_validation(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'kismi',
            'paid_amount' => 10,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Sale::count());
    }

    // 7. Çok ürünlü satış
    public function test_multi_item_sale_creates_one_stock_movement_per_item(): void
    {
        $productA = $this->product(stock: 10, price: 20);
        $productB = $this->product(stock: 5, price: 30);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2, 'unit_price' => 20],
                ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 30],
            ],
        ]);

        $sale = Sale::first();
        $this->assertSame(2, $sale->items()->count());
        $this->assertSame(70.0, (float) $sale->total);
        $this->assertSame(8, $productA->fresh()->current_stock);
        $this->assertSame(4, $productB->fresh()->current_stock);
        $this->assertSame(2, StockMovement::where('source_type', Sale::class)->count());
    }

    // 9. Yetersiz stok -> tüm işlem geri alınır (atomiklik)
    public function test_insufficient_stock_rolls_back_the_whole_sale(): void
    {
        $product = $this->product(stock: 2, price: 50);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 50]],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, CashTransaction::count());
        $this->assertSame(2, $product->fresh()->current_stock);
    }

    // İskonto ara toplamdan büyükse reddedilir
    public function test_discount_greater_than_subtotal_is_rejected(): void
    {
        $product = $this->product(stock: 10, price: 50);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'discount_total' => 1000,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Sale::count());
    }

    // 10. Eşzamanlı stok düşümü (sıralı simülasyon — SQLite test ortamında gerçek paralellik pratik değil)
    public function test_second_sale_cannot_oversell_remaining_stock(): void
    {
        $product = $this->product(stock: 5, price: 50);
        $admin = $this->admin();

        $first = $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ]);
        $first->assertRedirect();

        $second = $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]],
        ]);

        $second->assertSessionHas('error');
        $this->assertSame(1, $product->fresh()->current_stock);
        $this->assertSame(1, Sale::count());
    }

    // 12. Satış iptali: stok + cari + kasa atomik şekilde geri alınır
    public function test_cancelling_a_partial_sale_reverses_stock_account_and_cash(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(stock: 50, price: 100);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 400,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->post("/sales/{$sale->id}/cancel");

        $response->assertRedirect();
        $this->assertTrue($sale->fresh()->isCancelled());
        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(0.0, CashTransaction::balance());
        $this->assertSame(50, $product->fresh()->current_stock);
    }

    // 13. Çift iptal engeli
    public function test_a_sale_cannot_be_cancelled_twice(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();

        $this->actingAs($this->admin())->post("/sales/{$sale->id}/cancel");
        $response = $this->actingAs($this->admin())->post("/sales/{$sale->id}/cancel");

        $response->assertSessionHas('error');
    }

    // 14. account_transactions üzerinden iptal edilirse tüm satış zinciri terslenir
    public function test_cancelling_via_the_account_transaction_route_cascades_the_whole_sale(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(stock: 20, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();
        $debtTransaction = $sale->debtAccountTransaction;

        $response = $this->actingAs($this->admin())->post("/account-transactions/{$debtTransaction->id}/cancel");

        $response->assertRedirect();
        $this->assertTrue($sale->fresh()->isCancelled());
        $this->assertSame(0.0, $customer->fresh()->balance());
        $this->assertSame(20, $product->fresh()->current_stock);
    }

    // 15. cash_transactions üzerinden iptal edilirse tüm satış zinciri terslenir
    public function test_cancelling_via_the_cash_transaction_route_cascades_the_whole_sale(): void
    {
        $product = $this->product(stock: 20, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();
        $cashTransaction = $sale->cashTransaction;

        $response = $this->actingAs($this->admin())->post("/cash-transactions/{$cashTransaction->id}/cancel");

        $response->assertRedirect();
        $this->assertTrue($sale->fresh()->isCancelled());
        $this->assertSame(20, $product->fresh()->current_stock);
        $this->assertSame(0.0, CashTransaction::balance());
    }

    // WP-10c: cash-transactions/{id}/cancel route'u role:Admin altında —
    // hedef kayıt bir Sale'e ait (cascade-delegasyon dalı) olsa bile
    // Personel engellenmeli ve hiçbir yan etki oluşmamalı.
    public function test_personel_cannot_cancel_a_sale_sourced_cash_transaction_via_cash_route(): void
    {
        $product = $this->product(stock: 20, price: 50);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();
        $cashTransaction = $sale->cashTransaction;

        $response = $this->actingAs($this->personel())->post("/cash-transactions/{$cashTransaction->id}/cancel");

        $response->assertForbidden();
        $this->assertFalse($sale->fresh()->isCancelled());
        $this->assertSame(17, $product->fresh()->current_stock);
        $this->assertSame(150.0, CashTransaction::balance());
    }

    // account_id bir tedarikçiye aitse satış reddedilir
    public function test_sale_rejects_a_supplier_type_account(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $supplier->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(0, Sale::count());
    }

    // 15/16. Yetkisiz erişim
    public function test_personel_cannot_create_a_sale(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->personel())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Sale::count());
    }

    public function test_personel_cannot_cancel_a_sale(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($this->personel())->post("/sales/{$sale->id}/cancel");

        $response->assertForbidden();
        $this->assertFalse($sale->fresh()->isCancelled());
    }

    public function test_personel_can_view_sales_list_and_detail(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();

        $this->actingAs($this->personel())->get('/sales')->assertOk();
        $this->actingAs($this->personel())->get("/sales/{$sale->id}")->assertOk();
    }

    public function test_guest_is_redirected_from_sales(): void
    {
        $this->get('/sales')->assertRedirect('/login');
        $this->get('/sales/create')->assertRedirect('/login');
    }

    public function test_admin_can_view_the_sale_create_form(): void
    {
        $this->actingAs($this->admin())->get('/sales/create')->assertOk();
    }
}
