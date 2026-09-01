<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index');
    }

    public function lowStock()
    {
        $products = Product::with(['category', 'brand'])
            ->where('active', true)
            ->lowStock()
            ->orderBy('current_stock')
            ->paginate(25);

        return view('reports.low-stock', compact('products'));
    }

    /**
     * Read-only report: monetary value per movement = quantity × the movement's own
     * recorded unit_price. Never reads the product's current price. Movements without
     * a recorded currency (legacy data) are shown individually but excluded from the
     * per-currency subtotal, since their currency cannot be known retroactively.
     */
    public function movementValue(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);

        $movements = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->with(['product.category', 'customer', 'supplier'])
            ->orderByDesc('movement_date')
            ->paginate(25)
            ->withQueryString();

        $qtyTotals = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->selectRaw('type, SUM(quantity) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $totalsByCurrency = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->whereNotNull('currency')
            ->where('unit_price', '>', 0)
            ->selectRaw('currency, type, SUM(quantity * unit_price) as total')
            ->groupBy('currency', 'type')
            ->get()
            ->groupBy('currency');

        $productSummary = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->where('stock_movements.type', 'out')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->selectRaw('products.id as product_id, products.name, products.code, products.category_id, stock_movements.currency,
                SUM(stock_movements.quantity) as qty_out,
                SUM(CASE WHEN stock_movements.unit_price > 0 THEN stock_movements.quantity * stock_movements.unit_price ELSE 0 END) as amount')
            ->groupBy('products.id', 'products.name', 'products.code', 'products.category_id', 'stock_movements.currency')
            ->orderByDesc('qty_out')
            ->get();

        $categoryIds = $productSummary->pluck('category_id')->filter()->unique();
        $categoryNames = Category::whereIn('id', $categoryIds)->pluck('name', 'id');

        $products = Product::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $customers = Customer::orderBy('name')->get();
        $suppliers = Supplier::orderBy('name')->get();

        return view('reports.movement-value', compact(
            'movements', 'qtyTotals', 'totalsByCurrency', 'productSummary', 'categoryNames',
            'products', 'categories', 'customers', 'suppliers',
            'dateFrom', 'dateTo', 'period'
        ));
    }

    public function movementValueExport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $movements = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->with(['product.category'])
            ->orderByDesc('movement_date')
            ->get();

        $filename = 'stok-hareket-degeri-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($movements) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, ['Tarih', 'Ürün', 'Kod', 'Kategori', 'Tip', 'Miktar', 'Birim Fiyat', 'Para Birimi', 'Tutar']);

            foreach ($movements as $movement) {
                fputcsv($handle, [
                    $movement->movement_date->format('d.m.Y H:i'),
                    $movement->product->name,
                    $movement->product->code,
                    $movement->product->category?->name ?? '-',
                    $movement->type === 'in' ? 'Giriş' : 'Çıkış',
                    $movement->quantity,
                    $movement->amount() === null ? '' : number_format($movement->unit_price, 2, ',', '.'),
                    $movement->currency ?? 'Bilinmiyor',
                    $movement->amount() === null ? '' : number_format($movement->amount(), 2, ',', '.'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function movementValuePrint(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $movements = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->with(['product.category', 'customer', 'supplier'])
            ->orderByDesc('movement_date')
            ->get();

        $qtyTotals = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->selectRaw('type, SUM(quantity) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $totalsByCurrency = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->whereNotNull('currency')
            ->where('unit_price', '>', 0)
            ->selectRaw('currency, type, SUM(quantity * unit_price) as total')
            ->groupBy('currency', 'type')
            ->get()
            ->groupBy('currency');

        return view('reports.movement-value-print', compact('movements', 'qtyTotals', 'totalsByCurrency', 'dateFrom', 'dateTo'));
    }

    /**
     * Applies every shared filter (type, product, category, customer, supplier,
     * currency, date range) to a fresh StockMovement query. Called separately for
     * each aggregate so one filtered dataset backs the list, totals, and exports.
     */
    private function filteredMovements(Request $request, string $dateFrom, string $dateTo)
    {
        return StockMovement::query()
            ->when($request->type, fn ($q) => $q->where('stock_movements.type', $request->type))
            ->when($request->product_id, fn ($q) => $q->where('stock_movements.product_id', $request->product_id))
            ->when($request->category_id, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('category_id', $request->category_id)))
            ->when($request->customer_id, fn ($q) => $q->where('stock_movements.customer_id', $request->customer_id))
            ->when($request->supplier_id, fn ($q) => $q->where('stock_movements.supplier_id', $request->supplier_id))
            ->when($request->currency, fn ($q) => $q->where('stock_movements.currency', $request->currency))
            ->whereDate('stock_movements.movement_date', '>=', $dateFrom)
            ->whereDate('stock_movements.movement_date', '<=', $dateTo);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [dateFrom, dateTo, activePeriod]
     */
    private function resolveDateRange(Request $request): array
    {
        $period = $request->get('period');

        $range = match ($period) {
            'today' => [Carbon::today(), Carbon::today()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'last_month' => [Carbon::now()->subMonthNoOverflow()->startOfMonth(), Carbon::now()->subMonthNoOverflow()->endOfMonth()],
            default => null,
        };

        if ($range) {
            return [$range[0]->toDateString(), $range[1]->toDateString(), $period];
        }

        if ($request->date_from || $request->date_to) {
            return [
                $request->date_from ?: Carbon::now()->startOfMonth()->toDateString(),
                $request->date_to ?: Carbon::now()->endOfMonth()->toDateString(),
                'custom',
            ];
        }

        return [
            Carbon::now()->startOfMonth()->toDateString(),
            Carbon::now()->endOfMonth()->toDateString(),
            'this_month',
        ];
    }
}
