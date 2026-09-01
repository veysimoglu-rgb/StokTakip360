<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'type', 'quantity', 'unit_price', 'currency',
        'customer_id', 'supplier_id', 'user_id', 'note', 'movement_date',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
        'unit_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * quantity × recorded unit_price, or null when no price was recorded.
     * Never falls back to the product's current price.
     */
    public function amount(): ?float
    {
        if ($this->unit_price <= 0) {
            return null;
        }

        return $this->quantity * (float) $this->unit_price;
    }
}
