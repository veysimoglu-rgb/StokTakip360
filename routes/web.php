<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountTransactionController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CashLedgerController;
use App\Http\Controllers\CashTransactionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth', 'license'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');

    Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [AccountController::class, 'create'])->middleware('role:Admin')->name('accounts.create');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');

    Route::get('/cash', [CashTransactionController::class, 'index'])->name('cash.index');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/create', [SaleController::class, 'create'])->middleware('role:Admin')->name('sales.create');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');

    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/create', [PurchaseController::class, 'create'])->middleware('role:Admin')->name('purchases.create');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/low-stock', [ReportController::class, 'lowStock'])->name('reports.low-stock');
    Route::get('/reports/movement-value', [ReportController::class, 'movementValue'])->name('reports.movement-value');
    Route::get('/reports/movement-value/export', [ReportController::class, 'movementValueExport'])->name('reports.movement-value.export');
    Route::get('/reports/movement-value/print', [ReportController::class, 'movementValuePrint'])->name('reports.movement-value.print');

    Route::get('/reports/sales', [ReportController::class, 'salesReport'])->name('reports.sales');
    Route::get('/reports/sales/export', [ReportController::class, 'salesReportExport'])->name('reports.sales.export');
    Route::get('/reports/sales/print', [ReportController::class, 'salesReportPrint'])->name('reports.sales.print');

    Route::get('/reports/purchases', [ReportController::class, 'purchasesReport'])->name('reports.purchases');
    Route::get('/reports/purchases/export', [ReportController::class, 'purchasesReportExport'])->name('reports.purchases.export');
    Route::get('/reports/purchases/print', [ReportController::class, 'purchasesReportPrint'])->name('reports.purchases.print');

    Route::get('/reports/account-statement', [ReportController::class, 'accountStatement'])->name('reports.account-statement');
    Route::get('/reports/account-statement/export', [ReportController::class, 'accountStatementExport'])->name('reports.account-statement.export');
    Route::get('/reports/account-statement/print', [ReportController::class, 'accountStatementPrint'])->name('reports.account-statement.print');

    Route::get('/reports/cash', [ReportController::class, 'cashReport'])->name('reports.cash');
    Route::get('/reports/cash/export', [ReportController::class, 'cashReportExport'])->name('reports.cash.export');
    Route::get('/reports/cash/print', [ReportController::class, 'cashReportPrint'])->name('reports.cash.print');

    Route::get('/reports/profitability', [ReportController::class, 'profitability'])->name('reports.profitability');
    Route::get('/reports/profitability/export', [ReportController::class, 'profitabilityExport'])->name('reports.profitability.export');
    Route::get('/reports/profitability/print', [ReportController::class, 'profitabilityPrint'])->name('reports.profitability.print');

    Route::middleware('role:Admin')->group(function () {
        Route::get('/stock-in', [StockMovementController::class, 'createIn'])->name('stock-movements.in');
        Route::post('/stock-in', [StockMovementController::class, 'storeIn'])->name('stock-movements.in.store');
        Route::get('/stock-out', [StockMovementController::class, 'createOut'])->name('stock-movements.out');
        Route::post('/stock-out', [StockMovementController::class, 'storeOut'])->name('stock-movements.out.store');

        Route::resource('products', ProductController::class)->except(['index', 'show']);
        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show']);
        Route::resource('accounts', AccountController::class)->except(['index', 'show', 'create']);

        Route::post('/accounts/{account}/transactions', [AccountTransactionController::class, 'store'])->name('accounts.transactions.store');
        Route::post('/account-transactions/{accountTransaction}/cancel', [AccountTransactionController::class, 'cancel'])->name('account-transactions.cancel');
        Route::post('/accounts/{account}/collect', [CashLedgerController::class, 'collect'])->name('accounts.collect');
        Route::post('/accounts/{account}/pay', [CashLedgerController::class, 'pay'])->name('accounts.pay');

        Route::post('/cash', [CashTransactionController::class, 'store'])->name('cash.store');
        Route::post('/cash-transactions/{cashTransaction}/cancel', [CashTransactionController::class, 'cancel'])->name('cash-transactions.cancel');

        Route::post('/sales', [SaleController::class, 'store'])->name('sales.store');
        Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel'])->name('sales.cancel');

        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');

        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    });

    // Registered after the role:Admin group above (which contains the
    // literal GET /products/create route from Route::resource) so this
    // wildcard never swallows /products/create — see the WP-4/5 route-order
    // 404 lesson. Open to any authenticated user, same as products.index.
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
});

require __DIR__.'/auth.php';
