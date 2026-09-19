<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LayoutFooterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function footer(string $url): string
    {
        $content = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
        preg_match('#<footer\b.*?</footer>#s', $content, $match);

        $this->assertNotEmpty($match, "no footer on {$url}");

        return $match[0];
    }

    public function test_the_footer_is_fixed_system_text_with_a_safe_mikrolens_link(): void
    {
        $footer = $this->footer('/dashboard');

        $this->assertStringContainsString('StokTakip360 © '.date('Y').' — Geliştiren: ', $footer);
        $this->assertStringContainsString('<a href="https://mikrolens.com" target="_blank" rel="noopener noreferrer"', $footer);
        $this->assertMatchesRegularExpression('#>Mikrolens</a>#', $footer);
        $this->assertSame(1, substr_count($footer, '<a '));
    }

    // Ayarlar'daki firma adı/ünvanı footer'a hiçbir şekilde yansımaz
    public function test_the_footer_ignores_the_company_name_setting(): void
    {
        Setting::set('company_name', 'Fera Plastik Sanayi Ltd. Şti.');

        foreach (['/dashboard', '/products', '/sales', '/settings'] as $url) {
            $footer = $this->footer($url);

            $this->assertStringNotContainsString('Fera Plastik', $footer, "company name leaked into footer on {$url}");
            $this->assertStringContainsString('Geliştiren: <a href="https://mikrolens.com"', $footer);
        }

        Setting::set('company_name', '');
        $this->assertStringContainsString('>Mikrolens</a>', $this->footer('/dashboard'));
    }

    // Sabit konum: ekranın altına yapışık, masaüstünde kenar çubuğunun (16rem) sağından başlar, içerik için alt boşluk ayrılır
    public function test_the_footer_is_pinned_beside_the_sidebar_and_main_reserves_bottom_space(): void
    {
        $content = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('.app-footer { position: fixed; bottom: 0; left: 0; right: 0; z-index: 20; }', $content);
        $this->assertStringContainsString('@media (min-width: 1024px) { .app-footer { left: 16rem; } }', $content);
        $this->assertStringContainsString('main.app-main { padding-bottom: 4.5rem; }', $content);
        $this->assertStringContainsString('<main class="app-main ', $content);
        $this->assertStringContainsString('<footer class="app-footer ', $content);
        // kenar çubuğu genişliği ile footer ofseti aynı değer (w-64 = 16rem)
        $this->assertStringContainsString('w-64', $content);
    }

    // Footer yalnızca uygulama çerçevesinde: Sipariş Fişi çıktısına taşınmaz
    public function test_the_order_receipt_does_not_carry_the_app_footer(): void
    {
        $product = Product::factory()->create(['current_stock' => 10, 'sale_price' => 5, 'currency' => 'TL']);
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]],
        ]);

        $response = $this->actingAs($this->admin)->get(route('sales.receipt', Sale::first()));

        $response->assertOk();
        $response->assertDontSee('mikrolens.com');
        $response->assertDontSee('Geliştiren');
        $response->assertDontSee('<footer', false);
    }
}
