<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleReceiptTest extends TestCase
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

    private function product(float $price = 50): Product
    {
        return Product::factory()->create(['current_stock' => 100, 'sale_price' => $price, 'currency' => 'TL']);
    }

    // Anonim peşin sipariş: cari yok -> yalnızca Sipariş Toplamı, bakiye satırları yok
    public function test_cash_order_receipt_shows_only_order_total_without_a_cari(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee('Sipariş Fişi');
        $response->assertSee('Bu belge resmi fatura veya irsaliye yerine geçmez.');
        $response->assertSee('Genel Müşteri');
        $response->assertSee($sale->number);
        $response->assertSee('Sipariş Toplamı');
        $response->assertSee('150,00 TL');
        $response->assertDontSee('Devreden Bakiye');
        $response->assertDontSee('Toplam Bakiye');
        $response->assertDontSee('Satış Fişi');
    }

    // Ödenen / Kalan / Tam Ödendi / Ara Toplam alanları fişten kaldırıldı
    public function test_receipt_no_longer_shows_payment_status_or_subtotal_fields(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 60,
            'due_date' => now()->addDays(9)->toDateString(),
            'discount_total' => 10,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()));

        foreach (['Ara Toplam', 'İskonto', 'Tahsil Edilen', 'Ödenen', 'Kalan', 'Borç Yok', 'TAM ÖDENDİ', 'KISMİ ÖDENDİ', 'VADELİ / ÖDENMEDİ', 'Vade Tarihi'] as $removed) {
            $response->assertDontSee($removed);
        }
    }

    // Devreden + Sipariş = Toplam Bakiye (spesifikasyon örneği: 30.000 + 12.500 = 42.500)
    public function test_receipt_shows_order_total_carried_balance_and_total_balance(): void
    {
        $admin = $this->admin();
        $customer = Account::factory()->create(['type' => 'customer', 'phone' => '0532 111 22 33']);
        $customer->transactions()->create([
            'type' => 'manual_debt', 'direction' => 'debit', 'amount' => 12500, 'currency' => 'TL',
            'transaction_date' => now(), 'user_id' => $admin->id,
        ]);
        $product = $this->product(price: 3000);

        $this->actingAs($admin)->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 3000]],
        ]);

        $response = $this->actingAs($admin)->get(route('sales.receipt', Sale::first()));

        $response->assertOk();
        $response->assertSee($customer->name);
        $response->assertSee('0532 111 22 33');
        $response->assertSeeInOrder(['Sipariş Toplamı', '30.000,00 TL', 'Devreden Bakiye', '12.500,00 TL', 'Toplam Bakiye', '42.500,00 TL']);
    }

    // İskonto sipariş toplamına yansır ama fişte ayrı satır olarak gösterilmez
    public function test_receipt_total_reflects_discount_without_a_separate_discount_line(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'discount_total' => 20,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()));

        $response->assertOk();
        $response->assertSee('130,00 TL');
        $response->assertDontSee('İskonto');
    }

    // Paketli ürün: satır ağırlığı + en altta toplam ağırlık
    public function test_receipt_shows_package_quantity_line_weight_and_total_weight(): void
    {
        $product = Product::factory()->create([
            'current_stock' => 100000, 'sale_price' => 2, 'currency' => 'TL', 'unit' => 'Adet',
            'package_label' => 'Balya', 'package_qty' => 15, 'subunit_label' => 'Paket',
            'subunit_to_base_qty' => 100, 'package_weight_kg' => 8.5,
        ]);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 10, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()));

        $response->assertOk();
        // Ağırlık kolonu yok; miktar hücresi iki satır: ana metin balya, gri yardımcı satır balya × paket · ağırlık
        $response->assertDontSee('Ağırlık</th>', false);
        $response->assertSeeInOrder(['10 Balya', '<span class="sub">10 × 15 · 85 kg</span>', '<td class="text-right nowrap">15.000</td>'], false);
        $response->assertSeeInOrder(['Toplam Ağırlık', '85 kg']);
        $response->assertSee('30.000,00 TL');
    }

    // Spesifikasyon örneği: 7 Balya / 7 × 15 · 56 kg / Adet 10.500 — birim kelimeleri yardımcı satırda tekrarlanmaz
    public function test_receipt_shows_bale_helper_line_and_a_separate_adet_column(): void
    {
        $product = Product::factory()->create([
            'code' => 'STR-01', 'name' => 'Streç Film', 'current_stock' => 100000, 'sale_price' => 2, 'currency' => 'TL', 'unit' => 'Adet',
            'package_label' => 'Balya', 'package_qty' => 15, 'subunit_label' => 'Paket',
            'subunit_to_base_qty' => 100, 'package_weight_kg' => 8,
        ]);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 7, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()));

        $response->assertSeeInOrder([
            '<th class="code">Ürün Kodu</th>', '<th>Ürün</th>', '<th class="text-right nowrap">Miktar</th>',
            '<th class="text-right nowrap">Adet</th>', '<th class="text-right nowrap">Birim Fiyat</th>', '<th class="text-right nowrap">Tutar</th>',
        ], false);
        $response->assertDontSee('Ağırlık</th>', false);
        $response->assertSeeInOrder(['7 Balya', '<span class="sub">7 × 15 · 56 kg</span>'], false);
        $response->assertSeeInOrder(['<span class="sub">7 × 15 · 56 kg</span>', '<td class="text-right nowrap">10.500</td>', '2,00 TL', '21.000,00 TL'], false);
        // Yardımcı satırda birim kelimesi yok; eski "10.500 Adet · 56 kg" biçimi de yok
        $response->assertDontSee('10.500 Adet', false);
        $response->assertDontSee('7 × 15 Paket', false);
        $response->assertSeeInOrder(['Toplam Ağırlık', '56 kg']);
    }

    // Yardımcı satırdaki paket sayısı sipariş anındaki çarpandan gelir: ürünün paketleme ayarı sonra değişse de eski fiş bozulmaz
    public function test_bale_helper_line_uses_the_frozen_multiplier_not_the_products_current_packaging(): void
    {
        $product = Product::factory()->create([
            'current_stock' => 100000, 'sale_price' => 2, 'currency' => 'TL', 'unit' => 'Adet',
            'package_label' => 'Balya', 'package_qty' => 15, 'subunit_label' => 'Paket',
            'subunit_to_base_qty' => 100, 'package_weight_kg' => 8,
        ]);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'package_qty_input' => 6, 'unit_price' => 2]],
        ])->assertSessionHasNoErrors();

        $product->update(['package_qty' => 20]);

        $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()))
            ->assertSee('<span class="sub">6 × 15 · 48 kg</span>', false)
            ->assertDontSee('6 × 20', false);
    }

    // Paketsiz ürün: Miktar hücresi eskisi gibi "2 Adet", gri yardımcı satır yok, Adet sütununda gerçek adet (2) — "–" değil
    public function test_unpackaged_line_keeps_its_plain_quantity_and_has_no_helper_line(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]],
        ]);

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()));

        $response->assertSee('2 Adet');
        $response->assertSee('<td class="text-right nowrap">2</td>', false);
        $response->assertDontSee('<span class="sub">', false);
        $response->assertDontSee('Ağırlık', false);
        $response->assertDontSee('–', false);
    }

    // Fiş başlığı programı kullanan firma (Ayarlar > Firma Adı); müşteri adı yalnızca Müşteri alanında
    public function test_receipt_header_shows_the_company_name_and_the_customer_only_in_the_customer_field(): void
    {
        Setting::set('company_name', 'TEK-IN PACK AMBALAJ SAN. VE TİC. LTD. ŞTİ.');
        $product = $this->product();

        foreach (['Fera Plastik', 'Yılmaz Ambalaj Ltd. Şti.'] as $name) {
            $customer = Account::factory()->create(['type' => 'customer', 'name' => $name]);
            $this->actingAs($this->admin())->post('/sales', [
                'account_id' => $customer->id,
                'payment_type' => 'vadeli',
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
            ]);

            $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::latest('id')->first()));

            $response->assertSee('<h1>'.e('TEK-IN PACK AMBALAJ SAN. VE TİC. LTD. ŞTİ.').'</h1>', false);
            $response->assertSee('<h2>Sipariş Fişi</h2>', false);
            $response->assertDontSee('<h1>'.e($name).'</h1>', false);
            $response->assertSee('<strong>Müşteri:</strong> '.e($name), false);
            $response->assertSee('Bu belge resmi fatura veya irsaliye yerine geçmez.');
        }
    }

    // Firma adı yoksa başlık boş kalmaz: uygulama adı; müşteri adı başlığa sızmaz
    public function test_receipt_header_falls_back_to_the_app_name_without_a_company_name(): void
    {
        $customer = Account::factory()->create(['type' => 'customer', 'name' => 'Fera Plastik']);
        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        foreach ([null, '   '] as $blank) {
            Setting::set('company_name', $blank);

            $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()))
                ->assertSee('<h1>'.e(config('app.name')).'</h1>', false)
                ->assertDontSee('<h1>Fera Plastik</h1>', false)
                ->assertSee('<strong>Müşteri:</strong> Fera Plastik', false);
        }
    }

    // Cari yok: başlıkta firma, Müşteri alanında "Genel Müşteri"
    public function test_receipt_header_for_an_order_without_a_cari(): void
    {
        Setting::set('company_name', 'Fera Plastik Sanayi Ltd. Şti.');

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()))
            ->assertSee('<h1>Fera Plastik Sanayi Ltd. Şti.</h1>', false)
            ->assertDontSee('<h1>Genel Müşteri</h1>', false)
            ->assertSee('<strong>Müşteri:</strong> Genel Müşteri', false);
    }

    // Fişin en altındaki "… tarafından oluşturulmuştur." satırı kaldırıldı; geri kalan içerik duruyor
    public function test_receipt_no_longer_has_the_generated_by_footer(): void
    {
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()))
            ->assertOk()
            ->assertDontSee('tarafından oluşturulmuştur')
            ->assertSee('Sipariş Fişi')
            ->assertSee('Bu belge resmi fatura veya irsaliye yerine geçmez.')
            ->assertSee('Sipariş Toplamı');
    }

    // Fişte ürün kodu kolonu: Ürün Kodu | Ürün | Miktar | Adet | Birim Fiyat | Tutar
    public function test_receipt_lists_the_product_code_before_the_product_name(): void
    {
        $product = Product::factory()->create(['code' => 'NYL-3040', 'name' => 'Naylon Poşet', 'current_stock' => 100, 'sale_price' => 10, 'currency' => 'TL']);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]],
        ]);

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', Sale::first()));

        $response->assertSeeInOrder([
            '<th class="code">Ürün Kodu</th>', '<th>Ürün</th>', '<th class="text-right nowrap">Miktar</th>', '<th class="text-right nowrap">Adet</th>',
            '<th class="text-right nowrap">Birim Fiyat</th>', '<th class="text-right nowrap">Tutar</th>',
        ], false);
        $response->assertSeeInOrder(['<td class="code">NYL-3040</td>', '<td class="name">Naylon Poşet</td>'], false);
        $response->assertDontSee('Ağırlık</th>', false);
    }

    // Tarayıcının yazdırma başlığı (tarih/saat + sayfa başlığı) basılmasın: sayfa boşluğu 0, 10 mm gövde boşluğu olarak korunur
    public function test_receipt_suppresses_the_browser_print_header_but_keeps_the_order_number_line(): void
    {
        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);
        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertSee('@page { size: A5; margin: 0; }', false);
        $response->assertSee('body { padding: calc(10mm + 12px); }', false);
        $response->assertDontSee('@page { size: A5; margin: 10mm; }', false);
        $response->assertSee('<strong>Sipariş No:</strong> '.$sale->number, false);
        $response->assertSee('<h2>Sipariş Fişi</h2>', false);
    }

    // 9. Çok ürünlü satış
    public function test_receipt_lists_every_line_item(): void
    {
        $productA = $this->product(price: 10);
        $productB = $this->product(price: 20);

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2, 'unit_price' => 10],
                ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 20],
            ],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee($productA->name);
        $response->assertSee($productB->name);
    }

    // 13. Mevcut satış detay ekranı regresyonu — Fiş Yazdır linki eklendi, sayfa hâlâ açılıyor
    public function test_sale_show_page_still_renders_with_receipt_link(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.show', $sale));

        $response->assertOk();
        $response->assertSee(route('sales.receipt', $sale), false);
    }
}
