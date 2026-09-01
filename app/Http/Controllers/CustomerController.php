<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::when($request->q, fn ($q) => $q->where('name', 'like', "%{$request->q}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        return view('customers.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active', true);

        Customer::create($data);

        return redirect()->route('customers.index')->with('success', 'Müşteri eklendi.');
    }

    public function edit(Customer $customer)
    {
        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');

        $customer->update($data);

        return redirect()->route('customers.index')->with('success', 'Müşteri güncellendi.');
    }

    public function destroy(Customer $customer)
    {
        if ($customer->stockMovements()->exists()) {
            return back()->with('error', 'Bu müşteriye bağlı stok hareketleri var, silinemez.');
        }

        $customer->delete();

        return back()->with('success', 'Müşteri silindi.');
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
