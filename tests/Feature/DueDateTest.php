<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DueDateTest extends TestCase
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
        return Product::factory()->create(['current_stock' => $stock, 'sale_price' => $price, 'purchase_price' => $price, 'currency' => 'TL']);
    }

    // 1. Peşin + due_date yok -> başarılı
    public function test_cash_sale_without_due_date_succeeds(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertNull(Sale::first()->due_date);
    }

    // 2. Peşin + due_date var -> başarılı (opsiyonel olduğu için verilmesi de sorun değil)
    public function test_cash_sale_with_due_date_still_succeeds(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertNotNull(Sale::first()->due_date);
    }

    // 3. Vadeli + due_date yok -> reddedilir
    public function test_credit_sale_without_due_date_is_rejected(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Sale::count());
    }

    // 4. Kısmi + due_date yok -> reddedilir
    public function test_partial_sale_without_due_date_is_rejected(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 10,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Sale::count());
    }

    // 5. Vadeli + due_date var -> başarılı, doğru kaydediliyor
    public function test_credit_sale_with_due_date_succeeds_and_is_stored(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();
        $dueDate = now()->addDays(30)->toDateString();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => $dueDate,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertSame($dueDate, Sale::first()->due_date->toDateString());
    }

    // 6. Kısmi + due_date var -> başarılı
    public function test_partial_sale_with_due_date_succeeds(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product(price: 100);

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 20,
            'due_date' => now()->addDays(15)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $response->assertRedirect();
        $this->assertNotNull(Sale::first()->due_date);
    }

    // 7. Geçmiş tarihli due_date kabul edilir
    public function test_backdated_due_date_is_accepted(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subYear()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertNotNull(Sale::first()->due_date);
    }

    // Gelecekte due_date kabul edilir
    public function test_future_due_date_is_accepted(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addYear()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertNotNull(Sale::first()->due_date);
    }

    // Mevcut due_date'siz eski satış çalışır (regresyon: NULL due_date sorun çıkarmıyor)
    public function test_existing_sale_without_due_date_still_displays_correctly(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get("/sales/{$sale->id}");

        $response->assertOk();
        $this->assertNull($sale->due_date);
        $this->assertFalse($sale->isOverdue());
    }

    // Vadesi geçmiş + bakiye kalan satış -> overdue
    public function test_overdue_scope_finds_unpaid_past_due_sale(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();

        $this->assertTrue($sale->isOverdue());
        $this->assertSame(1, Sale::overdue()->count());
    }

    // Vadesi geçmiş + tamamen ödenmiş satış -> overdue değil
    public function test_fully_paid_sale_with_past_due_date_is_not_overdue(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();
        $sale->update(['paid_amount' => $sale->total]);

        $this->assertFalse($sale->fresh()->isOverdue());
        $this->assertSame(0, Sale::overdue()->count());
    }

    // Vadesi geçmiş + iptal edilmiş satış -> overdue değil
    public function test_cancelled_sale_with_past_due_date_is_not_overdue(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();
        $this->actingAs($this->admin())->post("/sales/{$sale->id}/cancel");

        $this->assertFalse($sale->fresh()->isOverdue());
        $this->assertSame(0, Sale::overdue()->count());
    }

    // Vade tarihi bugünse henüz vadesi geçmiş sayılmamalı (due_date < today, değil <=)
    public function test_sale_due_today_is_not_yet_overdue(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $this->assertFalse(Sale::first()->isOverdue());
        $this->assertSame(0, Sale::overdue()->count());
    }

    // --- Alış tarafı (Sale senaryolarının aynısı) ---

    public function test_credit_purchase_without_due_date_is_rejected(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Purchase::count());
    }

    public function test_partial_purchase_without_due_date_is_rejected(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'kismi',
            'paid_amount' => 10,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Purchase::count());
    }

    public function test_credit_purchase_with_due_date_succeeds_and_is_stored(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);
        $dueDate = now()->addDays(30)->toDateString();

        $response = $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => $dueDate,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertRedirect();
        $this->assertSame($dueDate, Purchase::first()->due_date->toDateString());
    }

    public function test_existing_purchase_without_due_date_still_displays_correctly(): void
    {
        $product = $this->product(stock: 0);
        $this->actingAs($this->admin())->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();

        $response = $this->actingAs($this->admin())->get("/purchases/{$purchase->id}");

        $response->assertOk();
        $this->assertNull($purchase->due_date);
        $this->assertFalse($purchase->isOverdue());
    }

    public function test_overdue_scope_finds_unpaid_past_due_purchase(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $purchase = Purchase::first();

        $this->assertTrue($purchase->isOverdue());
        $this->assertSame(1, Purchase::overdue()->count());
    }

    public function test_fully_paid_purchase_with_past_due_date_is_not_overdue(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();
        $purchase->update(['paid_amount' => $purchase->total]);

        $this->assertFalse($purchase->fresh()->isOverdue());
        $this->assertSame(0, Purchase::overdue()->count());
    }

    public function test_cancelled_purchase_with_past_due_date_is_not_overdue(): void
    {
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $this->actingAs($this->admin())->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $purchase = Purchase::first();
        $this->actingAs($this->admin())->post("/purchases/{$purchase->id}/cancel");

        $this->assertFalse($purchase->fresh()->isOverdue());
        $this->assertSame(0, Purchase::overdue()->count());
    }

    // --- Dashboard overdue toplamları ---

    public function test_dashboard_overdue_totals_are_correct(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $supplier = Account::factory()->create(['type' => 'supplier']);

        // Overdue sale: 1000 total, 300 paid -> 700 remaining, counted.
        $productA = $this->product(stock: 50, price: 100);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 300,
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['product_id' => $productA->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        // Not yet due sale: due in the future, must not be counted.
        $productB = $this->product(stock: 50, price: 50);
        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $productB->id, 'quantity' => 2, 'unit_price' => 50]],
        ]);

        // Overdue purchase: 500 total, unpaid -> 500 remaining, counted.
        $productC = $this->product(stock: 0, price: 500);
        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->subDays(1)->toDateString(),
            'items' => [['product_id' => $productC->id, 'quantity' => 1, 'unit_price' => 500]],
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $this->assertSame(700.0, $response->viewData('overdueSalesAmount'));
        $this->assertSame(500.0, $response->viewData('overduePurchasesAmount'));
    }

    // --- UI / form davranışı ---

    // due_date validation hatası sonrası form seçimi korunuyor
    public function test_create_form_remembers_due_date_after_a_validation_error(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();
        $dueDate = now()->addDays(20)->toDateString();

        $response = $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            // paid_amount omitted -> SaleService rejects kismi with no paid_amount, "error" flash not withErrors,
            // so instead trigger a validation-level failure: invalid discount forces service rollback with flashed
            // input, and due_date should still be visible on reload via old().
            'discount_total' => 'not-a-number',
            'due_date' => $dueDate,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertSessionHasErrors();

        // due_date is x-model-bound (JS-driven), not a static value="" attribute —
        // old() is reflected into Alpine's x-data string, same as paymentType/paidAmount.
        $this->actingAs($this->admin())
            ->get('/sales/create')
            ->assertSee("dueDate: '{$dueDate}'", false);
    }

    /**
     * Regression guard mirroring the paid_amount HTML5-validation bug: the
     * due_date field must live inside <template x-if="paymentType !== 'pesin'">
     * (mount/unmount), never inside an x-show wrapper that would leave a
     * required-but-hidden field in the DOM for a peşin submission.
     */
    public function test_due_date_field_is_only_rendered_inside_the_non_pesin_template(): void
    {
        $content = $this->actingAs($this->admin())->get('/sales/create')->getContent();

        $this->assertStringNotContainsString("x-show=\"paymentType !== 'pesin'\"", $content);
        $this->assertStringContainsString('<template x-if="paymentType !== \'pesin\'">', $content);

        $templateStart = strpos($content, '<template x-if="paymentType !== \'pesin\'">');
        $templateEnd = strpos($content, '</template>', $templateStart);
        $requiredAttributePosition = strpos($content, 'id="due_date"');

        $this->assertNotFalse($templateStart);
        $this->assertNotFalse($requiredAttributePosition);
        $this->assertGreaterThan($templateStart, $requiredAttributePosition);
        $this->assertLessThan($templateEnd, $requiredAttributePosition);
    }

    public function test_due_date_field_absent_for_purchases_create_pesin_template_too(): void
    {
        $content = $this->actingAs($this->admin())->get('/purchases/create')->getContent();

        $this->assertStringNotContainsString("x-show=\"paymentType !== 'pesin'\"", $content);
        $this->assertStringContainsString('<template x-if="paymentType !== \'pesin\'">', $content);
    }

    // --- Yetkilendirme ---

    public function test_personel_cannot_create_a_credit_sale_at_all(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $response = $this->actingAs($this->personel())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Sale::count());
    }

    public function test_guest_is_redirected_from_sales_create(): void
    {
        $this->get('/sales/create')->assertRedirect('/login');
    }
}
