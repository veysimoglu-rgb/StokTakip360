<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductListSortingTest extends TestCase
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

    /**
     * @return array<int, Product>
     */
    private function namesInOrder($response, string $viewKey = 'products'): array
    {
        return $response->viewData($viewKey)->pluck('name')->all();
    }

    public function test_default_product_list_still_works_without_any_sort_param(): void
    {
        Product::factory()->create(['name' => 'Zebra']);
        Product::factory()->create(['name' => 'Alfa']);

        $response = $this->actingAs($this->admin())->get('/products');

        $response->assertOk();
        $this->assertSame(['Alfa', 'Zebra'], $this->namesInOrder($response));
    }

    // --- Kategori ----------------------------------------------------------

    public function test_category_sort_ascending(): void
    {
        $catA = Category::create(['name' => 'A Kategori', 'active' => true]);
        $catZ = Category::create(['name' => 'Z Kategori', 'active' => true]);
        Product::factory()->create(['name' => 'Ürün Z-kat', 'category_id' => $catZ->id]);
        Product::factory()->create(['name' => 'Ürün A-kat', 'category_id' => $catA->id]);

        $response = $this->actingAs($this->admin())->get('/products?sort=category&direction=asc');

        $response->assertOk();
        $this->assertSame(['Ürün A-kat', 'Ürün Z-kat'], $this->namesInOrder($response));
    }

    public function test_category_sort_descending(): void
    {
        $catA = Category::create(['name' => 'A Kategori', 'active' => true]);
        $catZ = Category::create(['name' => 'Z Kategori', 'active' => true]);
        Product::factory()->create(['name' => 'Ürün A-kat', 'category_id' => $catA->id]);
        Product::factory()->create(['name' => 'Ürün Z-kat', 'category_id' => $catZ->id]);

        $response = $this->actingAs($this->admin())->get('/products?sort=category&direction=desc');

        $response->assertOk();
        $this->assertSame(['Ürün Z-kat', 'Ürün A-kat'], $this->namesInOrder($response));
    }

    // --- Stok ----------------------------------------------------------

    public function test_stock_sort_ascending(): void
    {
        Product::factory()->create(['name' => 'Yüksek Stok', 'current_stock' => 100]);
        Product::factory()->create(['name' => 'Düşük Stok', 'current_stock' => 5]);

        $response = $this->actingAs($this->admin())->get('/products?sort=stock&direction=asc');

        $response->assertOk();
        $this->assertSame(['Düşük Stok', 'Yüksek Stok'], $this->namesInOrder($response));
    }

    public function test_stock_sort_descending(): void
    {
        Product::factory()->create(['name' => 'Düşük Stok', 'current_stock' => 5]);
        Product::factory()->create(['name' => 'Yüksek Stok', 'current_stock' => 100]);

        $response = $this->actingAs($this->admin())->get('/products?sort=stock&direction=desc');

        $response->assertOk();
        $this->assertSame(['Yüksek Stok', 'Düşük Stok'], $this->namesInOrder($response));
    }

    // --- Satış Fiyatı (currency-scoped) -------------------------------------

    public function test_sale_price_sort_ascending_within_tl(): void
    {
        Product::factory()->create(['name' => 'TL Pahalı', 'currency' => 'TL', 'sale_price' => 500]);
        Product::factory()->create(['name' => 'TL Ucuz', 'currency' => 'TL', 'sale_price' => 50]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=asc&currency=TL');

        $response->assertOk();
        $this->assertSame(['TL Ucuz', 'TL Pahalı'], $this->namesInOrder($response));
    }

    public function test_sale_price_sort_descending_within_tl(): void
    {
        Product::factory()->create(['name' => 'TL Ucuz', 'currency' => 'TL', 'sale_price' => 50]);
        Product::factory()->create(['name' => 'TL Pahalı', 'currency' => 'TL', 'sale_price' => 500]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=desc&currency=TL');

        $response->assertOk();
        $this->assertSame(['TL Pahalı', 'TL Ucuz'], $this->namesInOrder($response));
    }

    public function test_sale_price_sort_ascending_within_usd(): void
    {
        Product::factory()->create(['name' => 'USD Pahalı', 'currency' => 'USD', 'sale_price' => 300]);
        Product::factory()->create(['name' => 'USD Ucuz', 'currency' => 'USD', 'sale_price' => 20]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=asc&currency=USD');

        $response->assertOk();
        $this->assertSame(['USD Ucuz', 'USD Pahalı'], $this->namesInOrder($response));
    }

    public function test_sale_price_sort_descending_within_usd(): void
    {
        Product::factory()->create(['name' => 'USD Ucuz', 'currency' => 'USD', 'sale_price' => 20]);
        Product::factory()->create(['name' => 'USD Pahalı', 'currency' => 'USD', 'sale_price' => 300]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=desc&currency=USD');

        $response->assertOk();
        $this->assertSame(['USD Pahalı', 'USD Ucuz'], $this->namesInOrder($response));
    }

    public function test_sale_price_sort_ascending_within_eur(): void
    {
        Product::factory()->create(['name' => 'EUR Pahalı', 'currency' => 'EUR', 'sale_price' => 120]);
        Product::factory()->create(['name' => 'EUR Ucuz', 'currency' => 'EUR', 'sale_price' => 15]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=asc&currency=EUR');

        $response->assertOk();
        $this->assertSame(['EUR Ucuz', 'EUR Pahalı'], $this->namesInOrder($response));
    }

    /**
     * The exact scenario from the request: 15 TL, 25 USD, 120 EUR must never
     * be compared against each other in one numeric price ordering.
     */
    public function test_different_currencies_are_never_mixed_in_price_sort(): void
    {
        Product::factory()->create(['name' => 'TL Ürün', 'currency' => 'TL', 'sale_price' => 15]);
        Product::factory()->create(['name' => 'USD Ürün', 'currency' => 'USD', 'sale_price' => 25]);
        Product::factory()->create(['name' => 'EUR Ürün', 'currency' => 'EUR', 'sale_price' => 120]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=asc&currency=USD');

        $response->assertOk();
        $names = $this->namesInOrder($response);
        $this->assertSame(['USD Ürün'], $names);
        $this->assertNotContains('TL Ürün', $names);
        $this->assertNotContains('EUR Ürün', $names);
    }

    public function test_sale_price_sort_is_ignored_when_no_currency_is_selected(): void
    {
        Product::factory()->create(['name' => 'B Ürün', 'currency' => 'TL', 'sale_price' => 500]);
        Product::factory()->create(['name' => 'A Ürün', 'currency' => 'USD', 'sale_price' => 5]);

        $response = $this->actingAs($this->admin())->get('/products?sort=sale_price&direction=asc');

        $response->assertOk();
        // Falls back to the default (name asc) instead of a meaningless
        // cross-currency numeric comparison.
        $this->assertSame(['A Ürün', 'B Ürün'], $this->namesInOrder($response));
    }

    // --- Filtrelerle birlikte çalışma ---------------------------------------

    public function test_search_filter_works_together_with_sorting(): void
    {
        Product::factory()->create(['name' => 'Kalem Büyük', 'code' => 'KLM-1', 'current_stock' => 50]);
        Product::factory()->create(['name' => 'Kalem Küçük', 'code' => 'KLM-2', 'current_stock' => 5]);
        Product::factory()->create(['name' => 'Defter', 'code' => 'DFT-1', 'current_stock' => 1]);

        $response = $this->actingAs($this->admin())->get('/products?q=Kalem&sort=stock&direction=asc');

        $response->assertOk();
        $this->assertSame(['Kalem Küçük', 'Kalem Büyük'], $this->namesInOrder($response));
    }

    public function test_category_filter_works_together_with_sorting(): void
    {
        $category = Category::create(['name' => 'Elektronik', 'active' => true]);
        $other = Category::create(['name' => 'Gıda', 'active' => true]);

        Product::factory()->create(['name' => 'Elektronik Büyük Stok', 'category_id' => $category->id, 'current_stock' => 100]);
        Product::factory()->create(['name' => 'Elektronik Küçük Stok', 'category_id' => $category->id, 'current_stock' => 3]);
        Product::factory()->create(['name' => 'Diğer Ürün', 'category_id' => $other->id, 'current_stock' => 1]);

        $response = $this->actingAs($this->admin())->get("/products?category_id={$category->id}&sort=stock&direction=asc");

        $response->assertOk();
        $this->assertSame(['Elektronik Küçük Stok', 'Elektronik Büyük Stok'], $this->namesInOrder($response));
    }

    public function test_low_stock_filter_works_together_with_sorting(): void
    {
        Product::factory()->create(['name' => 'Kritik Yüksek', 'current_stock' => 4, 'min_stock' => 5]);
        Product::factory()->create(['name' => 'Kritik Düşük', 'current_stock' => 1, 'min_stock' => 5]);
        Product::factory()->create(['name' => 'Kritik Değil', 'current_stock' => 500, 'min_stock' => 5]);

        $response = $this->actingAs($this->admin())->get('/products?low_stock=1&sort=stock&direction=asc');

        $response->assertOk();
        $this->assertSame(['Kritik Düşük', 'Kritik Yüksek'], $this->namesInOrder($response));
    }

    // --- Güvenlik: geçersiz parametreler -----------------------------------

    public function test_invalid_sort_parameter_falls_back_to_default_safely(): void
    {
        Product::factory()->create(['name' => 'Zebra']);
        Product::factory()->create(['name' => 'Alfa']);

        $response = $this->actingAs($this->admin())->get('/products?sort=DROP TABLE products;--&direction=asc');

        $response->assertOk();
        $this->assertSame(['Alfa', 'Zebra'], $this->namesInOrder($response));
    }

    public function test_invalid_direction_parameter_falls_back_to_ascending(): void
    {
        Product::factory()->create(['name' => 'Yüksek Stok', 'current_stock' => 100]);
        Product::factory()->create(['name' => 'Düşük Stok', 'current_stock' => 5]);

        $response = $this->actingAs($this->admin())->get('/products?sort=stock&direction=banana');

        $response->assertOk();
        $this->assertSame(['Düşük Stok', 'Yüksek Stok'], $this->namesInOrder($response));
    }
}
