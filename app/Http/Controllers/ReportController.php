<?php

namespace App\Http\Controllers;

use App\Models\Product;

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
}
