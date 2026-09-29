<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The sidebar only offers "Stok Hareketleri": stock-in comes from purchases
 * and stock-out from orders. The manual Stok Girişi / Çıkışı routes and
 * backend stay untouched (Admin only) for corrections.
 */
class StockMenuTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function sidebar(User $user, string $url = '/dashboard'): string
    {
        $content = $this->actingAs($user)->get($url)->assertOk()->getContent();
        preg_match('#<aside\b.*?</aside>#s', $content, $match);
        $this->assertNotEmpty($match);

        return $match[0];
    }

    public function test_the_sidebar_hides_stok_girisi_and_stok_cikisi_but_keeps_stok_hareketleri(): void
    {
        foreach (['Admin', 'Personel'] as $role) {
            $sidebar = $this->sidebar($this->user($role));

            $this->assertStringNotContainsString('Stok Girişi', $sidebar, $role);
            $this->assertStringNotContainsString('Stok Çıkışı', $sidebar, $role);
            $this->assertStringNotContainsString(route('stock-movements.in'), $sidebar, $role);
            $this->assertStringNotContainsString(route('stock-movements.out'), $sidebar, $role);
            $this->assertStringContainsString('Stok Hareketleri', $sidebar, $role);
            $this->assertStringContainsString(route('stock-movements.index'), $sidebar, $role);
            // The rest of the menu is untouched.
            foreach (['Siparişler', 'Alışlar', 'Cari Hesaplar', 'Kasa', 'Raporlar'] as $item) {
                $this->assertStringContainsString($item, $sidebar, $role);
            }
        }
    }

    public function test_the_sidebar_stays_simplified_while_on_the_manual_stock_screens(): void
    {
        $admin = $this->user('Admin');

        $this->assertStringNotContainsString('Stok Girişi', $this->sidebar($admin, '/stock-in'));
        $this->assertStringNotContainsString('Stok Çıkışı', $this->sidebar($admin, '/stock-out'));
    }

    public function test_manual_stock_routes_still_work_for_admin_only(): void
    {
        $admin = $this->user('Admin');
        $staff = $this->user('Personel');
        $product = Product::factory()->create(['current_stock' => 5]);

        $this->actingAs($admin)->get('/stock-in')->assertOk();
        $this->actingAs($admin)->get('/stock-out')->assertOk();
        $this->actingAs($admin)->post('/stock-in', ['product_id' => $product->id, 'quantity' => 10])->assertRedirect(route('stock-movements.index'));
        $this->actingAs($admin)->post('/stock-out', ['product_id' => $product->id, 'quantity' => 4])->assertRedirect(route('stock-movements.index'));
        $this->assertSame(11.0, (float) $product->fresh()->current_stock);

        $this->actingAs($staff)->get('/stock-in')->assertForbidden();
        $this->actingAs($staff)->get('/stock-out')->assertForbidden();
        $this->actingAs($staff)->post('/stock-in', ['product_id' => $product->id, 'quantity' => 10])->assertForbidden();
        $this->assertSame(11.0, (float) $product->fresh()->current_stock);
    }

    // Admin's manual entry points live on the movements page; a normal user no longer sees dead (403) buttons there
    public function test_movements_page_offers_the_manual_screens_to_admin_only(): void
    {
        $adminPage = $this->actingAs($this->user('Admin'))->get(route('stock-movements.index'))->assertOk();
        $adminPage->assertSee(route('stock-movements.in'), false);
        $adminPage->assertSee(route('stock-movements.out'), false);

        $staffPage = $this->actingAs($this->user('Personel'))->get(route('stock-movements.index'))->assertOk();
        $staffPage->assertDontSee(route('stock-movements.in'), false);
        $staffPage->assertDontSee(route('stock-movements.out'), false);
        $staffPage->assertSee('Stok Hareketleri');
    }
}
