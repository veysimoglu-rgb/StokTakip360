<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with(['category', 'brand'])
            ->when($request->q, function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request->q}%")
                        ->orWhere('code', 'like', "%{$request->q}%")
                        ->orWhere('barcode', 'like', "%{$request->q}%");
                });
            })
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->low_stock, fn ($q) => $q->lowStock())
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return view('products.index', compact('products', 'categories'));
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
            'min_stock' => ['required', 'integer', 'min:0'],
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
