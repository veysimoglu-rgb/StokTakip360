<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductShowTest extends TestCase
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

    private function product(string $currency = 'TL', float $purchasePrice = 40, float $salePrice = 80, int $stock = 50): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
            'currency' => $currency,
        ]);
    }

    // --- Route / erişim -----------------------------------------------

    public function test_product_show_route_returns_200(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->get(route('products.show', $product))->assertOk();
    }

    public function test_product_show_returns_404_for_nonexistent_product(): void
    {
        $this->actingAs($this->admin())->get('/products/999999')->assertNotFound();
    }

    public function test_personel_can_view_product_show(): void
    {
        $product = $this->product();

        $this->actingAs($this->personel())->get(route('products.show', $product))->assertOk();
    }

    public function test_guest_is_redirected_from_product_show(): void
    {
        $product = $this->product();

        $this->get(route('products.show', $product))->assertRedirect('/login');
    }

    /**
     * Regression guard for the exact route-order 404 bug fixed in WP-4/5:
     * products.show is a wildcard GET /products/{product}. If it were ever
     * registered before the admin resource group's literal GET
     * /products/create, that literal path would incorrectly match the
     * wildcard instead (product = "create") and 404. This proves the real
     * ordering in routes/web.php still resolves /products/create correctly.
     */
    public function test_products_create_route_still_resolves_after_adding_the_show_route(): void
    {
        $response = $this->actingAs($this->admin())->get('/products/create');

        $response->assertOk();
        $response->assertViewIs('products.create');
    }

    // --- İçerik ----------------------------------------------------------

    public function test_product_show_displays_general_and_price_info(): void
    {
        $category = Category::create(['name' => 'Elektronik', 'active' => true]);
        $product = Product::factory()->create([
            'name' => 'Test Ürünü XYZ',
            'category_id' => $category->id,
            'currency' => 'USD',
            'purchase_price' => 55,
            'sale_price' => 99,
        ]);

        $response = $this->actingAs($this->admin())->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('Test Ürünü XYZ');
        $response->assertSee('Elektronik');
        $response->assertSee('99,00 USD');
        $response->assertSee('55,00 USD');
    }

    public function test_product_show_displays_stock_summary_and_status(): void
    {
        $product = Product::factory()->create(['current_stock' => 3, 'min_stock' => 10]);

        $response = $this->actingAs($this->admin())->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('Kritik');
        $response->assertSeeText('3');
    }

    public function test_product_show_displays_sale_history_row(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 80]],
        ]);

        $response = $this->actingAs($admin)->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee(Sale::first()->number);
        $response->assertSee($customer->name);
    }

    public function test_product_show_displays_purchase_history_row(): void
    {
        $admin = $this->admin();
        $supplier = Account::factory()->create(['type' => 'supplier']);
        $product = $this->product(stock: 0);

        $this->actingAs($admin)->post('/purchases', [
            'account_id' => $supplier->id,
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 40]],
        ]);

        $response = $this->actingAs($admin)->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee(Purchase::first()->number);
        $response->assertSee($supplier->name);
    }

    public function test_product_show_displays_stock_movements(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80]],
        ]);

        $response = $this->actingAs($admin)->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('Çıkış');
        $response->assertSee(Sale::first()->number);
    }

    // --- Kâr / maliyet -----------------------------------------------------

    public function test_gross_profit_is_computed_when_cost_price_is_present(): void
    {
        $admin = $this->admin();
        $product = $this->product(purchasePrice: 30, salePrice: 80);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 80]],
        ]);

        $item = SaleItem::first();
        $this->assertSame(150.0, $item->grossProfit()); // (80-30)*3

        $response = $this->actingAs($admin)->get(route('products.show', $product));
        $response->assertOk();
        $response->assertSee('150,00');
    }

    public function test_shows_no_cost_info_message_when_cost_price_is_null(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $sale = Sale::create([
            'number' => 'SAT-LEGACY-1',
            'payment_type' => 'pesin',
            'subtotal' => 80,
            'discount_total' => 0,
            'total' => 80,
            'paid_amount' => 80,
            'currency' => 'TL',
            'status' => 'paid',
            'sale_date' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 80,
            'line_total' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertNull($item->grossProfit());

        $response = $this->actingAs($admin)->get(route('products.show', $product));
        $response->assertOk();
        $response->assertSee('Maliyet bilgisi yok');
    }

    // --- Currency ----------------------------------------------------------

    public function test_tl_usd_eur_products_display_their_own_currency_correctly(): void
    {
        $admin = $this->admin();

        foreach (['TL', 'USD', 'EUR'] as $currency) {
            $product = $this->product(currency: $currency, purchasePrice: 20, salePrice: 40);

            $response = $this->actingAs($admin)->get(route('products.show', $product));

            $response->assertOk();
            $response->assertSee("40,00 {$currency}");
        }
    }

    public function test_usd_product_detail_page_returns_200(): void
    {
        $product = $this->product(currency: 'USD');

        $this->actingAs($this->admin())->get(route('products.show', $product))->assertOk();
    }

    public function test_eur_product_detail_page_returns_200(): void
    {
        $product = $this->product(currency: 'EUR');

        $this->actingAs($this->admin())->get(route('products.show', $product))->assertOk();
    }

    public function test_usd_sale_history_shows_the_correct_currency(): void
    {
        $admin = $this->admin();
        $product = $this->product(currency: 'USD', salePrice: 120);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 120]],
        ]);

        $response = $this->actingAs($admin)->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('240,00 USD');
        $response->assertDontSee('240,00 TL');
    }

    public function test_eur_sale_history_shows_the_correct_currency(): void
    {
        $admin = $this->admin();
        $product = $this->product(currency: 'EUR', salePrice: 90);

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 90]],
        ]);

        $response = $this->actingAs($admin)->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('90,00 EUR');
        $response->assertDontSee('90,00 TL');
    }

    public function test_usd_and_eur_summary_cards_show_correct_totals_without_mixing(): void
    {
        $admin = $this->admin();
        $usdProduct = $this->product(currency: 'USD', salePrice: 100);
        $eurProduct = $this->product(currency: 'EUR', salePrice: 50);

        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 100]]]);
        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $eurProduct->id, 'quantity' => 1, 'unit_price' => 50]]]);

        $usdResponse = $this->actingAs($admin)->get(route('products.show', $usdProduct));
        $eurResponse = $this->actingAs($admin)->get(route('products.show', $eurProduct));

        // The page CONTENT must not mix currencies. The global header carries the TCMB USD/EUR
        // indicator on every page, so only <main> is inspected, not the whole document.
        $main = fn ($response) => preg_match('#<main.*?</main>#s', $response->getContent(), $m) ? $m[0] : '';

        $usdResponse->assertOk();
        $usdResponse->assertSee('100,00 USD');
        $this->assertStringNotContainsString('EUR', $main($usdResponse));

        $eurResponse->assertOk();
        $eurResponse->assertSee('50,00 EUR');
        $this->assertStringNotContainsString('USD', $main($eurResponse));
    }

    /**
     * Regression test for the exact bug found in manual browser testing:
     * legacy stock_movements rows (created before the currency column
     * existed — see the 2026_09_01_183507 migration, which intentionally
     * leaves them NULL rather than guessing) must never reach
     * Currency::format() with a null currency, whether unit_price is
     * positive (products 4/5 in the real report) or the decimal-cast string
     * "0.00" (product 10 — PHP treats that string as truthy, so a naive
     * `$movement->unit_price ? ... : '-'` guard is NOT enough; the fix must
     * check $movement->currency itself, not infer safety from unit_price).
     */
    public function test_legacy_stock_movement_with_null_currency_and_positive_price_does_not_crash(): void
    {
        $product = $this->product();

        DB::table('stock_movements')->insert([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 5,
            'unit_price' => 120,
            'currency' => null,
            'movement_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('Bilinmiyor');
    }

    public function test_legacy_stock_movement_with_null_currency_and_zero_price_does_not_crash(): void
    {
        $product = $this->product();

        DB::table('stock_movements')->insert([
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 1,
            'unit_price' => 0,
            'currency' => null,
            'movement_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('products.show', $product));

        $response->assertOk();
    }

    // --- İptal ---------------------------------------------------------

    public function test_cancelled_sale_is_shown_in_history_with_a_badge(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $this->actingAs($admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80]],
        ]);
        $sale = Sale::first();
        $this->actingAs($admin)->post("/sales/{$sale->id}/cancel");

        $response = $this->actingAs($admin)->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee($sale->number);
        $response->assertSee('İptal');
    }

    // --- Performans ------------------------------------------------------

    public function test_product_show_does_not_produce_excessive_queries_for_multiple_sale_rows(): void
    {
        $admin = $this->admin();
        $product = $this->product(stock: 100);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->post('/sales', [
                'payment_type' => 'pesin',
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80]],
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('products.show', $product))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A handful of fixed queries (product, summaries, 3 paginated lists +
        // their eager loads) — not one query per sale/purchase/movement row.
        $this->assertLessThan(30, $queryCount);
    }

    // --- Liste bağlantısı --------------------------------------------------

    public function test_product_index_links_to_the_show_page(): void
    {
        $product = $this->product();

        $response = $this->actingAs($this->admin())->get('/products');

        $response->assertOk();
        $response->assertSee(route('products.show', $product), false);
    }
}
