<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Finds the price a customer was last actually charged for a product, used
 * only to pre-fill the unit price of a new order line (read-only: nothing is
 * written and no ledger is touched).
 */
class LastSalePriceFinder
{
    /**
     * The latest non-cancelled order of this customer that contains the
     * product in the product's own currency. Other customers, other
     * currencies and cancelled orders never match. $exceptSaleId lets the
     * edit screen skip the order being edited.
     *
     * @return array{unit_price: float, currency: string, number: string, date: string, sale_id: int}|null
     */
    public function find(int $accountId, Product $product, ?int $exceptSaleId = null): ?array
    {
        $item = SaleItem::query()
            ->select('sale_items.*')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.account_id', $accountId)
            ->where('sales.currency', $product->currency)
            ->whereNull('sales.cancelled_at')
            ->where('sale_items.product_id', $product->id)
            ->when($exceptSaleId, fn ($q) => $q->where('sales.id', '!=', $exceptSaleId))
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->orderByDesc('sale_items.id')
            ->with('sale')
            ->first();

        if (! $item) {
            return null;
        }

        return [
            'unit_price' => self::effectiveUnitPrice($item, $item->sale),
            'currency' => $item->sale->currency,
            'number' => $item->sale->number,
            'date' => $item->sale->sale_date->format('d.m.Y'),
            'sale_id' => $item->sale->id,
        ];
    }

    /**
     * What one unit really cost the customer: the order-level discount is
     * spread proportionally over every line, so 10.000 × 1,50 with a 10 %
     * discount comes out as 1,35 — not the list price 1,50.
     */
    public static function effectiveUnitPrice(SaleItem $item, Sale $sale): float
    {
        $subtotal = (float) $sale->subtotal;
        $factor = $subtotal > 0 ? (float) $sale->total / $subtotal : 1.0;

        return round((float) $item->unit_price * $factor, 2);
    }
}
