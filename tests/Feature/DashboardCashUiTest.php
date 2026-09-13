<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WP-8c UI/UX refactor: these tests check the *shape* of the rendered HTML
 * (section headings, responsive toggle classes, single-container structure,
 * absence of blended totals) — not the underlying currency math, which is
 * already covered by CurrencyAwareReportsTest and DashboardTest.
 */
class DashboardCashUiTest extends TestCase
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

    private function product(string $currency, int $stock = 50, float $price = 100): Product
    {
        return Product::factory()->create([
            'current_stock' => $stock,
            'sale_price' => $price,
            'purchase_price' => $price,
            'currency' => $currency,
        ]);
    }

    // =========================== DASHBOARD ===========================

    public function test_dashboard_has_a_single_financial_summary_section(): void
    {
        $content = $this->actingAs($this->admin())->get('/dashboard')->getContent();

        $this->assertSame(1, substr_count($content, 'Finansal Özet'));
    }

    public function test_dashboard_financial_summary_uses_one_responsive_card_grid_not_a_table(): void
    {
        $content = $this->actingAs($this->admin())->get('/dashboard')->getContent();

        // One card-based layout for every breakpoint (3-up from 640px,
        // single column below it) — no separate desktop table to keep in
        // sync, and no table markup at all on this page.
        $this->assertStringContainsString('sm:grid-cols-3', $content);
        $this->assertStringNotContainsString('<table', $content);
    }

    public function test_dashboard_vade_takibi_section_is_hidden_when_nothing_is_overdue(): void
    {
        $content = $this->actingAs($this->admin())->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('Vade Takibi', $content);
    }

    public function test_dashboard_vade_takibi_section_appears_only_for_currencies_with_overdue_activity(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer']);
        $tlProduct = $this->product('TL', price: 100);
        $usdProduct = $this->product('USD', price: 50);

        foreach ([$tlProduct, $usdProduct] as $product) {
            $this->actingAs($admin)->post('/sales', [
                'account_id' => $customer->id,
                'payment_type' => 'vadeli',
                'due_date' => now()->subDays(5)->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => $product->sale_price]],
            ]);
        }

        $content = $this->actingAs($admin)->get('/dashboard')->getContent();

        $this->assertSame(1, substr_count($content, 'Vade Takibi'));
        $this->assertStringContainsString('Vadesi Geçen Satış', $content);
    }

    public function test_dashboard_stock_summary_shows_exactly_four_cards(): void
    {
        $this->product('TL');

        $content = $this->actingAs($this->admin())->get('/dashboard')->getContent();

        $this->assertStringContainsString('Aktif Ürün Sayısı', $content);
        $this->assertStringContainsString('Kritik Stok', $content);
        $this->assertStringContainsString('Bugünkü Stok Girişi / Çıkışı', $content);
        $this->assertStringContainsString('Toplam Stok Değeri', $content);
    }

    public function test_dashboard_never_renders_a_blended_currency_total(): void
    {
        $admin = $this->admin();
        $tlProduct = $this->product('TL', price: 1000);
        $usdProduct = $this->product('USD', price: 700);

        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $tlProduct->id, 'quantity' => 1, 'unit_price' => 1000]]]);
        $this->actingAs($admin)->post('/sales', ['payment_type' => 'pesin', 'items' => [['product_id' => $usdProduct->id, 'quantity' => 1, 'unit_price' => 700]]]);

        $content = $this->actingAs($admin)->get('/dashboard')->getContent();

        // 1000 TL + 700 USD must never be blended into "1.700".
        $this->assertStringNotContainsString('1.700,00', $content);
        $this->assertStringContainsString('1.000,00', $content);
        $this->assertStringContainsString('700,00', $content);
    }

    public function test_dashboard_has_no_table_markup_to_ever_overflow_horizontally(): void
    {
        $content = $this->actingAs($this->admin())->get('/dashboard')->getContent();

        // The currency summaries are cards on every breakpoint now — there
        // is no table on this page at all, so there is nothing that needs
        // an overflow-x-auto escape hatch in the first place.
        $this->assertStringNotContainsString('<table', $content);
        $this->assertStringNotContainsString('overflow-x-auto', $content);
    }

    // =========================== KASA ===========================

    public function test_cash_index_has_a_single_kasa_ozeti_container(): void
    {
        $content = $this->actingAs($this->admin())->get('/cash')->getContent();

        $this->assertSame(1, substr_count($content, 'Kasa Özeti'));
        // The old per-currency stacked cards ("Kasa Bakiyesi (TL)" etc.)
        // must be gone — replaced by the single summary section.
        $this->assertStringNotContainsString('Kasa Bakiyesi (TL)', $content);
    }

    public function test_cash_index_summary_uses_one_responsive_card_grid(): void
    {
        $content = $this->actingAs($this->admin())->get('/cash')->getContent();

        // The summary is cards at every breakpoint (3-up from 640px); the
        // one real <table> on this page is the movement list further down,
        // whose own responsive columns (hidden sm:table-cell) are untouched.
        $this->assertStringContainsString('sm:grid-cols-3', $content);
        $this->assertStringContainsString('hidden sm:table-cell', $content);
    }

    public function test_cash_index_shows_tl_and_usd_without_blending_them(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 1000, 'currency' => 'TL']);
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'currency' => 'USD']);

        $content = $this->actingAs($this->admin())->get('/cash')->getContent();

        $this->assertStringContainsString('1.000,00', $content);
        $this->assertStringContainsString('500,00', $content);
        $this->assertStringNotContainsString('1.500,00', $content);
    }

    public function test_cash_index_movement_table_columns_are_unchanged(): void
    {
        CashTransaction::factory()->create(['type' => 'manual_in', 'direction' => 'in', 'amount' => 500, 'currency' => 'USD']);

        $response = $this->actingAs($this->admin())->get('/cash');

        $response->assertSee('Tarih');
        $response->assertSee('Tip');
        $response->assertSee('Açıklama');
        $response->assertSee('Cari');
        $response->assertSee('Para Birimi');
        $response->assertSee('Tutar');
        $response->assertSee('Kullanıcı');
        $response->assertSee('İşlem');
    }
}
