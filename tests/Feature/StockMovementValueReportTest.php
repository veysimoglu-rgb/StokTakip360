<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockMovementValueReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    public function test_report_renders_without_error_on_legacy_movements_missing_currency(): void
    {
        $product = Product::factory()->create(['currency' => 'USD']);

        // Simulates a pre-migration record: unit_price set, currency left NULL.
        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 10,
            'unit_price' => 50,
            'currency' => null,
            'movement_date' => now(),
        ]);

        // Simulates a legacy record with no price recorded at all (default 0).
        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 3,
            'unit_price' => 0,
            'currency' => null,
            'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value');

        $response->assertOk();
        $response->assertSee('para birimi bilinmiyor');
    }

    public function test_new_stock_out_records_products_current_currency(): void
    {
        $product = Product::factory()->create(['currency' => 'EUR', 'current_stock' => 20, 'sale_price' => 30]);

        $this->actingAs($this->admin())->post('/stock-out', [
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 30,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'currency' => 'EUR',
            'unit_price' => 30,
        ]);
    }

    public function test_historical_movement_price_is_unaffected_by_later_product_price_change(): void
    {
        $product = Product::factory()->create(['sale_price' => 100, 'current_stock' => 50]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 2,
            'unit_price' => 100,
            'currency' => $product->currency,
            'movement_date' => now(),
        ]);

        $product->update(['sale_price' => 500]);

        $this->assertSame(100.0, (float) $movement->fresh()->unit_price);
        $this->assertSame(200.0, $movement->fresh()->amount());
    }

    public function test_amount_is_null_when_unit_price_not_recorded(): void
    {
        $product = Product::factory()->create();

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 10,
            'unit_price' => 0,
            'movement_date' => now(),
        ]);

        $this->assertNull($movement->amount());
    }

    public function test_report_totals_exclude_movements_without_recorded_currency(): void
    {
        $product = Product::factory()->create(['currency' => 'TL']);

        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 10,
            'unit_price' => 50, 'currency' => 'TL', 'movement_date' => now(),
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 10,
            'unit_price' => 999, 'currency' => null, 'movement_date' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/reports/movement-value');

        $response->assertOk();

        // The per-row amount is still shown (the 9.990,00 row, flagged "para birimi bilinmiyor"),
        // but the currency subtotal must only reflect the movement with a recorded currency.
        $totals = $response->viewData('totalsByCurrency');
        $this->assertCount(1, $totals);
        $this->assertEquals(500, $totals->get('TL')->firstWhere('type', 'in')->total);
    }
}
