<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;

class DashboardController extends Controller
{
    public function index()
    {
        $totalProducts = Product::where('active', true)->count();
        $lowStockCount = Product::where('active', true)->lowStock()->count();
        $stockValueByCurrency = Product::where('active', true)
            ->selectRaw('currency, SUM(current_stock * purchase_price) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $todayIn = StockMovement::where('type', 'in')->whereDate('movement_date', today())->sum('quantity');
        $todayOut = StockMovement::where('type', 'out')->whereDate('movement_date', today())->sum('quantity');

        $lowStockProducts = Product::where('active', true)->lowStock()->orderBy('current_stock')->limit(5)->get();

        $recentMovements = StockMovement::with(['product', 'user'])
            ->orderByDesc('movement_date')
            ->limit(8)
            ->get();

        return view('dashboard', compact(
            'totalProducts', 'lowStockCount', 'stockValueByCurrency',
            'todayIn', 'todayOut', 'lowStockProducts', 'recentMovements'
        ));
    }
}
