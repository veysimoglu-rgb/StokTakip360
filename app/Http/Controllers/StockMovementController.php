<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $movements = StockMovement::with(['product', 'customer', 'supplier', 'user'])
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->product_id, fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('movement_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('movement_date', '<=', $request->date_to))
            ->orderByDesc('movement_date')
            ->paginate(25)
            ->withQueryString();

        $products = Product::orderBy('name')->get();

        return view('stock-movements.index', compact('movements', 'products'));
    }

    public function createIn()
    {
        $products = Product::where('active', true)->orderBy('name')->get();
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();

        return view('stock-movements.in', compact('products', 'suppliers'));
    }

    public function storeIn(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $product = Product::lockForUpdate()->findOrFail($data['product_id']);

            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'in',
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'] ?? 0,
                'supplier_id' => $data['supplier_id'] ?? null,
                'user_id' => $request->user()->id,
                'note' => $data['note'] ?? null,
                'movement_date' => $data['movement_date'] ?? now(),
            ]);

            $product->increment('current_stock', $data['quantity']);
        });

        return redirect()->route('stock-movements.index')->with('success', 'Stok girişi kaydedildi.');
    }

    public function createOut()
    {
        $products = Product::where('active', true)->orderBy('name')->get();
        $customers = Customer::where('active', true)->orderBy('name')->get();

        return view('stock-movements.out', compact('products', 'customers'));
    }

    public function storeOut(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
        ]);

        $error = null;

        DB::transaction(function () use ($data, $request, &$error) {
            $product = Product::lockForUpdate()->findOrFail($data['product_id']);

            if ($product->current_stock < $data['quantity']) {
                $error = 'Yetersiz stok. Mevcut stok: '.$product->current_stock;

                return;
            }

            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'out',
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'] ?? 0,
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $request->user()->id,
                'note' => $data['note'] ?? null,
                'movement_date' => $data['movement_date'] ?? now(),
            ]);

            $product->decrement('current_stock', $data['quantity']);
        });

        if ($error) {
            return back()->withInput()->with('error', $error);
        }

        return redirect()->route('stock-movements.index')->with('success', 'Stok çıkışı kaydedildi.');
    }
}
