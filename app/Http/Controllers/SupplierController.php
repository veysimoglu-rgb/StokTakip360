<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::when($request->q, fn ($q) => $q->where('name', 'like', "%{$request->q}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('suppliers.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active', true);

        Supplier::create($data);

        return redirect()->route('suppliers.index')->with('success', 'Tedarikçi eklendi.');
    }

    public function edit(Supplier $supplier)
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');

        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('success', 'Tedarikçi güncellendi.');
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->stockMovements()->exists()) {
            return back()->with('error', 'Bu tedarikçiye bağlı stok hareketleri var, silinemez.');
        }

        $supplier->delete();

        return back()->with('success', 'Tedarikçi silindi.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_no' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
