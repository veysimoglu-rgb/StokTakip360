<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCurrencySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    public function test_new_product_form_defaults_to_settings_vat_and_currency(): void
    {
        Setting::set('default_vat_rate', '10');
        Setting::set('currency', 'USD');

        $response = $this->actingAs($this->admin())->get('/products/create');

        $response->assertOk();
        $response->assertSee('value="10"', false);
        $response->assertSee('<option value="USD" selected>', false);
    }

    public function test_creating_product_does_not_change_existing_products_vat(): void
    {
        Setting::set('default_vat_rate', '10');
        Setting::set('currency', 'TL');

        $existing = Product::factory()->create(['vat_rate' => 20, 'currency' => 'USD']);

        $this->actingAs($this->admin())->post('/products', [
            'code' => 'NEW-001',
            'name' => 'New Product',
            'unit' => 'Adet',
            'min_stock' => 0,
            'purchase_price' => 10,
            'sale_price' => 15,
            'currency' => 'TL',
            'vat_rate' => 10,
            'active' => 1,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $existing->id,
            'vat_rate' => 20,
            'currency' => 'USD',
        ]);
        $this->assertDatabaseHas('products', [
            'code' => 'NEW-001',
            'vat_rate' => 10,
            'currency' => 'TL',
        ]);
    }

    public function test_changing_product_currency_does_not_convert_price(): void
    {
        $product = Product::factory()->create([
            'currency' => 'TL',
            'purchase_price' => 100,
            'sale_price' => 150,
        ]);

        $this->actingAs($this->admin())->put("/products/{$product->id}", [
            'code' => $product->code,
            'name' => $product->name,
            'unit' => $product->unit,
            'min_stock' => $product->min_stock,
            'purchase_price' => 100,
            'sale_price' => 150,
            'currency' => 'USD',
            'vat_rate' => $product->vat_rate,
            'active' => 1,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'currency' => 'USD',
            'purchase_price' => 100,
            'sale_price' => 150,
        ]);
    }

    public function test_dashboard_groups_stock_value_by_currency(): void
    {
        Product::factory()->create(['currency' => 'TL', 'current_stock' => 5, 'purchase_price' => 500, 'active' => true]);
        Product::factory()->create(['currency' => 'USD', 'current_stock' => 10, 'purchase_price' => 100, 'active' => true]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Toplam Stok Değeri (TL)');
        $response->assertSee('Toplam Stok Değeri (USD)');
        $response->assertDontSee('Toplam Stok Değeri (EUR)');
        $response->assertSee('2.500,00');
        $response->assertSee('1.000,00');
    }

    public function test_currency_format_uses_turkish_number_format(): void
    {
        $this->assertSame('1.250,50 USD', Currency::format(1250.5, 'USD'));
        $this->assertSame('$ 1.250,50', Currency::formatWithSymbol(1250.5, 'USD'));
    }
}
