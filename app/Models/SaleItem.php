<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id', 'product_id', 'quantity', 'stock_shortfall_quantity',
        'package_qty_input', 'unit_multiplier_snapshot', 'line_weight_kg',
        'unit_price', 'cost_price', 'line_total', 'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'stock_shortfall_quantity' => 'decimal:3',
        'package_qty_input' => 'decimal:3',
        'line_weight_kg' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * (unit_price - cost_price) * quantity, in this line's own currency —
     * or null when no cost snapshot exists (legacy pre-WP-9a rows), never 0.
     * Callers must render that as "Maliyet bilgisi yok", not as zero profit.
     */
    public function grossProfit(): ?float
    {
        if ($this->cost_price === null) {
            return null;
        }

        return ((float) $this->unit_price - (float) $this->cost_price) * $this->quantity;
    }
}
