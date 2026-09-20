<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "+ Satır Ekle" sits under ALL product rows (not in the card header) in both the order and the
 * purchase form — left-aligned with the first row on wide screens, centred and touch-sized on
 * narrow ones — and still just calls the form's own addItem().
 */
class AddRowButtonLayoutTest extends TestCase
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

    /** @return array<string, string> label => url */
    private function formUrls(): array
    {
        $product = Product::factory()->create(['current_stock' => 50, 'sale_price' => 10, 'purchase_price' => 5, 'currency' => 'TL']);

        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/purchases', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]],
        ])->assertSessionHasNoErrors();

        return [
            'sales.create' => '/sales/create',
            'sales.edit' => '/sales/'.Sale::first()->id.'/edit',
            'purchases.create' => '/purchases/create',
            'purchases.edit' => '/purchases/'.Purchase::first()->id.'/edit',
        ];
    }

    public function test_the_button_sits_below_all_product_rows_in_every_order_and_purchase_form(): void
    {
        foreach ($this->formUrls() as $label => $url) {
            $content = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($content, '+ Satır Ekle'), "{$label}: exactly one add-row button");
            $this->assertSame(1, substr_count($content, '@click="addItem()"'), "{$label}: addItem() is wired once");

            $button = strpos($content, '+ Satır Ekle');
            $header = strpos($content, 'Para Birimi:');
            $lastRowControl = strrpos(substr($content, 0, $button), 'removeItem(index)');
            $payment = strpos($content, 'Ödeme Tipi', $button);

            $this->assertNotFalse($lastRowControl, "{$label}: rows come before the button");
            $this->assertGreaterThan($header, $button, "{$label}: button is not in the card header any more");
            $this->assertGreaterThan($lastRowControl, $button, "{$label}: button is below the last product row");
            $this->assertNotFalse($payment, "{$label}: payment section still follows the button");
        }
    }

    public function test_the_button_is_left_aligned_on_wide_screens_and_centred_and_touch_sized_on_narrow_ones(): void
    {
        $content = $this->actingAs($this->admin)->get('/sales/create')->assertOk()->getContent();

        // narrow (default): centred, >= 44px tall touch target
        $this->assertStringContainsString('.add-row { display: flex; justify-content: center;', $content);
        $this->assertStringContainsString('min-height: 2.75rem;', $content);
        // >= 900px (same breakpoint as the compact product rows): compact and left-aligned
        $this->assertStringContainsString('@media (min-width: 900px)', $content);
        $this->assertStringContainsString('.add-row { justify-content: flex-start;', $content);
        $this->assertStringContainsString('.add-row-btn { min-height: 0;', $content);
    }

    public function test_the_row_logic_and_keyboard_behaviour_are_untouched(): void
    {
        $sales = $this->actingAs($this->admin)->get('/sales/create')->assertOk()->getContent();

        $this->assertStringContainsString('addItem() { this.items.push({', $sales);
        $this->assertStringContainsString('removeItem(i) { if (this.items.length > 1) this.items.splice(i, 1) }', $sales);
        $this->assertStringContainsString('@keydown.enter="onEnter($event)"', $sales);
        $this->assertStringContainsString('class="order-row grid grid-cols-1 gap-2 border-b pb-3 last:border-0"', $sales);
        $this->assertStringContainsString('@media (min-width: 900px)', $sales);

        $purchases = $this->actingAs($this->admin)->get('/purchases/create')->assertOk()->getContent();

        $this->assertStringContainsString('addItem() { this.items.push({ product_id: \'\', quantity: 1, unit_price: 0 }) }', $purchases);
        $this->assertStringContainsString('sm:grid-cols-[2fr_1fr_1fr_1fr_auto]', $purchases);
    }
}
