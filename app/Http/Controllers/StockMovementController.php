<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $movements = StockMovement::with(['product', 'account', 'user'])
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
        $accounts = Account::where('active', true)->whereIn('type', ['supplier', 'other'])->orderBy('name')->get();

        return view('stock-movements.in', compact('products', 'accounts'));
    }

    public function storeIn(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
        ]);

        if (! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);

            if (! in_array($account->type, ['supplier', 'other'])) {
                return back()->withInput()->withErrors(['account_id' => 'Stok girişi için tedarikçi veya diğer tipinde bir cari seçilmelidir.']);
            }
        }

        DB::transaction(function () use ($data, $request) {
            $product = Product::lockForUpdate()->findOrFail($data['product_id']);

            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'in',
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'] ?? 0,
                'currency' => $product->currency,
                'account_id' => $data['account_id'] ?? null,
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
        $accounts = Account::where('active', true)->whereIn('type', ['customer', 'other'])->orderBy('name')->get();

        return view('stock-movements.out', compact('products', 'accounts'));
    }

    public function storeOut(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'movement_date' => ['nullable', 'date'],
        ]);

        if (! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);

            if (! in_array($account->type, ['customer', 'other'])) {
                return back()->withInput()->withErrors(['account_id' => 'Stok çıkışı için müşteri veya diğer tipinde bir cari seçilmelidir.']);
            }
        }

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
                'currency' => $product->currency,
                'account_id' => $data['account_id'] ?? null,
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
