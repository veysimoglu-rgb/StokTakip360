<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockMovementAccountTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    private function personel(): User
    {
        Role::firstOrCreate(['name' => 'Personel']);
        $user = User::factory()->create();
        $user->assignRole('Personel');

        return $user;
    }

    // 1. Tedarikçi cari ile stok girişi
    public function test_stock_in_with_a_supplier_account_succeeds(): void
    {
        $product = Product::factory()->create(['current_stock' => 5]);
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $response = $this->actingAs($this->admin())->post('/stock-in', [
            'product_id' => $product->id,
            'quantity' => 10,
            'account_id' => $supplier->id,
        ]);

        $response->assertRedirect();
        $this->assertSame(15, $product->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'account_id' => $supplier->id,
        ]);
    }

    // 2. Müşteri cari ile stok çıkışı
    public function test_stock_out_with_a_customer_account_succeeds(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);
        $customer = Account::factory()->create(['type' => 'customer']);

        $response = $this->actingAs($this->admin())->post('/stock-out', [
            'product_id' => $product->id,
            'quantity' => 4,
            'account_id' => $customer->id,
        ]);

        $response->assertRedirect();
        $this->assertSame(6, $product->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'account_id' => $customer->id,
        ]);
    }

    // 'other' tipi her iki tarafta da kabul edilmeli
    public function test_stock_in_and_out_accept_other_type_account(): void
    {
        $admin = $this->admin();
        $other = Account::factory()->create(['type' => 'other']);
        $product = Product::factory()->create(['current_stock' => 10]);

        $this->actingAs($admin)->post('/stock-in', [
            'product_id' => $product->id, 'quantity' => 1, 'account_id' => $other->id,
        ])->assertRedirect();

        $this->actingAs($admin)->post('/stock-out', [
            'product_id' => $product->id, 'quantity' => 1, 'account_id' => $other->id,
        ])->assertRedirect();

        $this->assertSame(2, StockMovement::where('account_id', $other->id)->count());
    }

    // 3. Yanlış tip cari reddedilmeli — stok girişinde müşteri seçilemez
    public function test_stock_in_rejects_a_customer_type_account(): void
    {
        $product = Product::factory()->create(['current_stock' => 5]);
        $customer = Account::factory()->create(['type' => 'customer']);

        $response = $this->actingAs($this->admin())->post('/stock-in', [
            'product_id' => $product->id,
            'quantity' => 10,
            'account_id' => $customer->id,
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());
    }

    // Yanlış tip cari reddedilmeli — stok çıkışında tedarikçi seçilemez
    public function test_stock_out_rejects_a_supplier_type_account(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);
        $supplier = Account::factory()->create(['type' => 'supplier']);

        $response = $this->actingAs($this->admin())->post('/stock-out', [
            'product_id' => $product->id,
            'quantity' => 4,
            'account_id' => $supplier->id,
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertSame(10, $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());
    }

    // 4. Hareket listesinde cari adı görünmeli
    public function test_movement_list_shows_the_account_name(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);
        $customer = Account::factory()->create(['type' => 'customer', 'name' => 'Görünür Cari A.Ş.']);

        $this->actingAs($this->admin())->post('/stock-out', [
            'product_id' => $product->id,
            'quantity' => 2,
            'account_id' => $customer->id,
        ]);

        $response = $this->actingAs($this->admin())->get('/stock-movements');

        $response->assertOk();
        $response->assertSee('Görünür Cari A.Ş.');
    }

    // Cari seçilmeden de stok giriş/çıkışı çalışmalı (opsiyonel alan)
    public function test_stock_movements_still_work_without_selecting_an_account(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/stock-in', ['product_id' => $product->id, 'quantity' => 5])->assertRedirect();
        $this->actingAs($admin)->post('/stock-out', ['product_id' => $product->id, 'quantity' => 3])->assertRedirect();

        $this->assertSame(12, $product->fresh()->current_stock);
    }

    // 5. movement-value raporunda tek "Cari" dropdown'u tüm cari tiplerini (customer/supplier/other) listelemeli
    //    ve seçilen cariye göre hareket listesi doğru filtrelenmeli.
    public function test_movement_value_report_account_filter_returns_only_matching_movements(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);
        $customer = Account::factory()->create(['type' => 'customer', 'name' => 'Filtre Müşterisi']);
        $other = Account::factory()->create(['type' => 'other', 'name' => 'Diğer Cari']);

        StockMovement::create([
            'product_id' => $product->id, 'type' => 'out', 'quantity' => 1,
            'unit_price' => 10, 'currency' => 'TL', 'account_id' => $customer->id, 'movement_date' => now(),
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'out', 'quantity' => 5,
            'unit_price' => 10, 'currency' => 'TL', 'account_id' => $other->id, 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get("/reports/movement-value?account_id={$customer->id}&period=this_month");

        $response->assertOk();
        $response->assertSee('Filtre Müşterisi'); // dropdown seçimi

        $movements = $response->viewData('movements');
        $this->assertCount(1, $movements);
        $this->assertSame($customer->id, $movements->first()->account_id);

        $accounts = $response->viewData('accounts');
        $this->assertTrue($accounts->pluck('id')->contains($customer->id));
        $this->assertTrue($accounts->pluck('id')->contains($other->id));
    }

    // WP-10b: manuel stok giriş/çıkışı Admin'e özel — Personel erişememeli
    public function test_personel_cannot_view_the_stock_in_form(): void
    {
        $this->actingAs($this->personel())->get('/stock-in')->assertForbidden();
    }

    public function test_personel_cannot_store_a_stock_in_movement(): void
    {
        $product = Product::factory()->create(['current_stock' => 5]);

        $response = $this->actingAs($this->personel())->post('/stock-in', [
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $response->assertForbidden();
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_personel_cannot_view_the_stock_out_form(): void
    {
        $this->actingAs($this->personel())->get('/stock-out')->assertForbidden();
    }

    public function test_personel_cannot_store_a_stock_out_movement(): void
    {
        $product = Product::factory()->create(['current_stock' => 10]);

        $response = $this->actingAs($this->personel())->post('/stock-out', [
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $response->assertForbidden();
        $this->assertSame(10, $product->fresh()->current_stock);
        $this->assertSame(0, StockMovement::count());
    }

    // Admin için davranış WP-10b öncesiyle aynı kalmalı
    public function test_admin_can_still_view_stock_in_and_stock_out_forms(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/stock-in')->assertOk();
        $this->actingAs($admin)->get('/stock-out')->assertOk();
    }
}
