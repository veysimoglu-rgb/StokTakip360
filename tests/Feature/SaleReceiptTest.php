<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    // 1 & 5. Peşin / tam ödenmiş satış — kalan borç 0, "Borç Yok", TAM ÖDENDİ
    public function test_cash_sale_receipt_shows_paid_in_full(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee('Genel Müşteri');
        $response->assertSee($sale->number);
        $response->assertSee('150,00 TL');
        $response->assertSee('Borç Yok');
        $response->assertSee('TAM ÖDENDİ');
    }

    // 2, 3 & 4. Kısmi ödeme: 3.500 toplam, 1.000 tahsilat, 2.500 kalan + vade tarihi
    public function test_partial_payment_receipt_shows_correct_remaining_debt_and_due_date(): void
    {
        $customer = Account::factory()->create(['type' => 'customer', 'phone' => '0532 111 22 33']);
        $product = $this->product(price: 350);
        $dueDate = now()->addDays(9)->toDateString();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'kismi',
            'paid_amount' => 1000,
            'due_date' => $dueDate,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 350]],
        ]);

        $sale = Sale::first();
        $this->assertSame(3500.0, (float) $sale->total);
        $this->assertSame(1000.0, (float) $sale->paid_amount);
        $this->assertSame(2500.0, $sale->remaining());

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee($customer->name);
        $response->assertSee('0532 111 22 33');
        $response->assertSee('3.500,00 TL');
        $response->assertSee('1.000,00 TL');
        $response->assertSee('2.500,00 TL');
        $response->assertSee(Carbon::parse($dueDate)->format('d.m.Y'));
        $response->assertSee('KISMİ ÖDENDİ');
    }

    // 3. Vadeli satış — hiç ödeme yok
    public function test_credit_sale_receipt_shows_full_amount_as_remaining_debt(): void
    {
        $customer = Account::factory()->create(['type' => 'customer']);
        $product = $this->product();
        $dueDate = now()->addDays(30)->toDateString();

        $this->actingAs($this->admin())->post('/sales', [
            'account_id' => $customer->id,
            'payment_type' => 'vadeli',
            'due_date' => $dueDate,
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee('0,00 TL');
        $response->assertSee('200,00 TL');
        $response->assertSee('VADELİ / ÖDENMEDİ');
        $response->assertSee(Carbon::parse($dueDate)->format('d.m.Y'));
    }

    // 6. İskontolu satış
    public function test_receipt_shows_discount_when_present(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())->post('/sales', [
            'payment_type' => 'pesin',
            'discount_total' => 20,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 50]],
        ]);

        $sale = Sale::first();

        $response = $this->actingAs($this->admin())->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee('İskonto');
        $response->assertSee('-20,00 TL');
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
