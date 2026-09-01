<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $totalProducts = Product::where('active', true)->count();
        $lowStockCount = Product::where('active', true)->lowStock()->count();
        $stockValue = Product::where('active', true)->sum(DB::raw('current_stock * purchase_price'));

        $todayIn = StockMovement::where('type', 'in')->whereDate('movement_date', today())->sum('quantity');
        $todayOut = StockMovement::where('type', 'out')->whereDate('movement_date', today())->sum('quantity');

        $lowStockProducts = Product::where('active', true)->lowStock()->orderBy('current_stock')->limit(5)->get();

        $recentMovements = StockMovement::with(['product', 'user'])
            ->orderByDesc('movement_date')
            ->limit(8)
            ->get();

        return view('dashboard', compact(
            'totalProducts', 'lowStockCount', 'stockValue',
            'todayIn', 'todayOut', 'lowStockProducts', 'recentMovements'
        ));
    }
}
