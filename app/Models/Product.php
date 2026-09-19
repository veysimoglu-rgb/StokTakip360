<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'barcode', 'name', 'category_id', 'brand_id', 'unit',
        'package_label', 'package_qty', 'subunit_label', 'subunit_to_base_qty', 'package_weight_kg',
        'min_stock', 'current_stock', 'shelf_location',
        'purchase_price', 'sale_price', 'currency', 'vat_rate', 'description', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'current_stock' => 'decimal:3',
        'min_stock' => 'decimal:3',
        'package_qty' => 'integer',
        'subunit_to_base_qty' => 'integer',
        'package_weight_kg' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
    ];

    public function hasPackaging(): bool
    {
        return $this->package_qty > 0 && $this->subunit_to_base_qty > 0;
    }

    /** How many base units (adet) one package (balya) contains. */
    public function baseUnitsPerPackage(): int
    {
        return $this->hasPackaging() ? $this->package_qty * $this->subunit_to_base_qty : 0;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('current_stock', '<=', 'min_stock');
    }

    /**
     * Same threshold as scopeLowStock(), plus a distinct "out of stock" state
     * for the product detail page's status badge.
     */
    public function stockStatusLabel(): string
    {
        return match (true) {
            $this->current_stock <= 0 => 'Tükendi',
            $this->current_stock <= $this->min_stock => 'Kritik',
            default => 'Stokta',
        };
    }
}
