<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private const SORTABLE_COLUMNS = ['category', 'stock', 'sale_price'];

    public function index(Request $request)
    {
        $sort = in_array($request->sort, self::SORTABLE_COLUMNS, true) ? $request->sort : null;
        $direction = $request->direction === 'desc' ? 'desc' : 'asc';

        // Sorting by price only ever makes sense within one currency — with
        // no currency selected, sorting by sale_price would silently rank
        // TL/USD/EUR values against each other as if they were the same
        // unit. The currency filter below already scopes every row in the
        // result to one currency, so once it's set the ORDER BY is safe.
        if ($sort === 'sale_price' && ! $request->currency) {
            $sort = null;
        }

        $products = Product::with(['category', 'brand'])
            ->when($request->q, function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request->q}%")
                        ->orWhere('code', 'like', "%{$request->q}%")
                        ->orWhere('barcode', 'like', "%{$request->q}%");
                });
            })
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->currency, fn ($q) => $q->where('currency', $request->currency))
            ->when($request->low_stock, fn ($q) => $q->lowStock())
            ->when($sort === 'category', fn ($q) => $q->orderBy(Category::select('name')->whereColumn('categories.id', 'products.category_id'), $direction))
            ->when($sort === 'stock', fn ($q) => $q->orderBy('current_stock', $direction))
            ->when($sort === 'sale_price', fn ($q) => $q->orderBy('sale_price', $direction))
            ->when($sort === null, fn ($q) => $q->orderBy('name'))
            ->paginate(20)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return view('products.index', compact('products', 'categories', 'sort', 'direction'));
    }

    public function create()
    {
        $categories = Category::where('active', true)->orderBy('name')->get();
        $brands = Brand::where('active', true)->orderBy('name')->get();
        $defaultVatRate = Setting::get('default_vat_rate', 20);
        $defaultCurrency = Setting::get('currency', 'TL');

        return view('products.create', compact('categories', 'brands', 'defaultVatRate', 'defaultCurrency'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active', true);
        $data['current_stock'] = 0;

        Product::create($data);

        return redirect()->route('products.index')->with('success', 'Ürün eklendi.');
    }

    /**
     * Read-only product detail page: general info, current price/cost,
     * paginated sale/purchase/stock-movement history, and DB-computed
     * per-currency sale/purchase summaries — never a single blended total
     * across currencies (WP-8 rule), even though in practice almost every
     * product only ever transacts in its own current currency.
     */
    public function show(Product $product)
    {
        $product->load(['category', 'brand']);

        $saleItems = SaleItem::query()
            ->select('sale_items.*')
            ->where('sale_items.product_id', $product->id)
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sale_items.id')
            ->with('sale.account')
            ->paginate(10, ['*'], 'satislar')
            ->withQueryString();

        $purchaseItems = PurchaseItem::query()
            ->select('purchase_items.*')
            ->where('purchase_items.product_id', $product->id)
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->with('purchase.account')
            ->paginate(10, ['*'], 'alislar')
            ->withQueryString();

        $stockMovements = $product->stockMovements()
            ->with(['account', 'user', 'source'])
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'hareketler')
            ->withQueryString();

        $saleSummaryByCurrency = $this->saleSummaryByCurrency($product);
        $purchaseSummaryByCurrency = $this->purchaseSummaryByCurrency($product);

        return view('products.show', compact(
            'product', 'saleItems', 'purchaseItems', 'stockMovements',
            'saleSummaryByCurrency', 'purchaseSummaryByCurrency'
        ));
    }

    /**
     * One grouped aggregate query — never a PHP loop over rows. Cancelled
     * sales are excluded, same convention as every other report in this app.
     */
    private function saleSummaryByCurrency(Product $product): Collection
    {
        return SaleItem::query()
            ->where('sale_items.product_id', $product->id)
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.cancelled_at')
            ->selectRaw('sales.currency as currency, SUM(sale_items.quantity) as qty, SUM(sale_items.line_total) as total, MAX(sales.sale_date) as last_date, AVG(sale_items.unit_price) as avg_price')
            ->groupBy('sales.currency')
            ->get()
            ->keyBy('currency');
    }

    private function purchaseSummaryByCurrency(Product $product): Collection
    {
        return PurchaseItem::query()
            ->where('purchase_items.product_id', $product->id)
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNull('purchases.cancelled_at')
            ->selectRaw('purchases.currency as currency, SUM(purchase_items.quantity) as qty, SUM(purchase_items.line_total) as total, MAX(purchases.purchase_date) as last_date, AVG(purchase_items.unit_price) as avg_price')
            ->groupBy('purchases.currency')
            ->get()
            ->keyBy('currency');
    }

    public function edit(Product $product)
    {
        $categories = Category::where('active', true)->orderBy('name')->get();
        $brands = Brand::where('active', true)->orderBy('name')->get();

        return view('products.edit', compact('product', 'categories', 'brands'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product->id);
        $data['active'] = $request->boolean('active');

        $product->update($data);

        return redirect()->route('products.index')->with('success', 'Ürün güncellendi.');
    }

    public function destroy(Product $product)
    {
        if ($product->stockMovements()->exists()) {
            return back()->with('error', 'Bu ürüne ait stok hareketleri var, silinemez.');
        }

        $product->delete();

        return back()->with('success', 'Ürün silindi.');
    }

    private function validated(Request $request, ?int $productId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:products,code,'.$productId],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode,'.$productId],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit' => ['required', 'string', 'max:50'],
            'min_stock' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'package_label' => ['nullable', 'string', 'max:50'],
            'package_qty' => ['nullable', 'integer', 'min:1', 'required_with:subunit_to_base_qty'],
            'subunit_label' => ['nullable', 'string', 'max:50'],
            'subunit_to_base_qty' => ['nullable', 'integer', 'min:1', 'required_with:package_qty'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(Currency::LIST)],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
