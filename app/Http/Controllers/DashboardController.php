<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Currency;
use Illuminate\Support\Collection;

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

        // Every money metric below is computed once per currency (GROUP BY,
        // never a PHP loop over Currency::LIST) so TL/USD/EUR are never
        // summed together into one blended number. TL is the primary card
        // value (kept at its old variable names/semantics for the existing
        // 9-card grid); USD/EUR — when they have any activity — get their
        // own compact summary card below it.
        $kasaByCurrency = CashTransaction::balancesByCurrency();
        $receivableByCurrency = $this->accountTypeBalancesByCurrency(['customer', 'other']);
        $payableByCurrency = $this->accountTypeBalancesByCurrency(['supplier']);
        $todaySalesByCurrency = $this->sumByCurrency(Sale::whereNull('cancelled_at')->whereDate('sale_date', today()), 'total');
        $todayPurchasesByCurrency = $this->sumByCurrency(Purchase::whereNull('cancelled_at')->whereDate('purchase_date', today()), 'total');
        $pendingCollectionByCurrency = $this->sumByCurrency(Sale::whereNull('cancelled_at')->whereColumn('paid_amount', '<', 'total'), 'total - paid_amount');
        $pendingPaymentByCurrency = $this->sumByCurrency(Purchase::whereNull('cancelled_at')->whereColumn('paid_amount', '<', 'total'), 'total - paid_amount');
        $overdueSalesByCurrency = $this->sumByCurrency(Sale::overdue(), 'total - paid_amount');
        $overduePurchasesByCurrency = $this->sumByCurrency(Purchase::overdue(), 'total - paid_amount');

        $cashBalance = (float) ($kasaByCurrency['TL'] ?? 0);
        $totalReceivable = max(0, (float) ($receivableByCurrency['TL'] ?? 0));
        $totalPayable = max(0, (float) ($payableByCurrency['TL'] ?? 0));
        $todaySales = (float) ($todaySalesByCurrency['TL'] ?? 0);
        $todayPurchases = (float) ($todayPurchasesByCurrency['TL'] ?? 0);
        $pendingCollection = (float) ($pendingCollectionByCurrency['TL'] ?? 0);
        $pendingPayment = (float) ($pendingPaymentByCurrency['TL'] ?? 0);
        $overdueSalesAmount = (float) ($overdueSalesByCurrency['TL'] ?? 0);
        $overduePurchasesAmount = (float) ($overduePurchasesByCurrency['TL'] ?? 0);

        $foreignCurrencySummaries = collect(Currency::LIST)
            ->reject(fn ($currency) => $currency === 'TL')
            ->map(fn ($currency) => [
                'currency' => $currency,
                'kasa' => (float) ($kasaByCurrency[$currency] ?? 0),
                'receivable' => max(0, (float) ($receivableByCurrency[$currency] ?? 0)),
                'payable' => max(0, (float) ($payableByCurrency[$currency] ?? 0)),
                'today_sales' => (float) ($todaySalesByCurrency[$currency] ?? 0),
                'today_purchases' => (float) ($todayPurchasesByCurrency[$currency] ?? 0),
                'pending_collection' => (float) ($pendingCollectionByCurrency[$currency] ?? 0),
                'pending_payment' => (float) ($pendingPaymentByCurrency[$currency] ?? 0),
                'overdue_sales' => (float) ($overdueSalesByCurrency[$currency] ?? 0),
                'overdue_purchases' => (float) ($overduePurchasesByCurrency[$currency] ?? 0),
            ])
            ->filter(fn ($row) => collect($row)->except('currency')->contains(fn ($value) => $value != 0.0))
            ->values();

        return view('dashboard', compact(
            'totalProducts', 'lowStockCount', 'stockValueByCurrency',
            'todayIn', 'todayOut', 'lowStockProducts', 'recentMovements',
            'cashBalance', 'totalReceivable', 'totalPayable',
            'todaySales', 'todayPurchases', 'pendingCollection', 'pendingPayment',
            'overdueSalesAmount', 'overduePurchasesAmount', 'foreignCurrencySummaries'
        ));
    }

    /**
     * Site-wide net balance (debit - credit) across every account of the
     * given type(s), grouped by currency in one aggregate query — never a
     * per-account or per-currency loop. Mirrors Account::balanceForCurrency()'s
     * own debit/credit convention, just summed across many accounts and
     * split by currency instead of one account/one currency.
     */
    private function accountTypeBalancesByCurrency(array $types): Collection
    {
        return Account::query()
            ->join('account_transactions', 'account_transactions.account_id', '=', 'accounts.id')
            ->whereIn('accounts.type', $types)
            ->selectRaw("account_transactions.currency as currency, SUM(CASE WHEN account_transactions.direction = 'debit' THEN account_transactions.amount ELSE -account_transactions.amount END) as total")
            ->groupBy('account_transactions.currency')
            ->pluck('total', 'currency')
            ->map(fn ($value) => (float) $value);
    }

    /**
     * Applies a SUM($expression) GROUP BY currency to an already-filtered
     * Sale/Purchase query builder — the shared shape behind every
     * per-currency money metric on this page.
     */
    private function sumByCurrency($query, string $expression): Collection
    {
        return $query->selectRaw("currency, SUM({$expression}) as total")
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($value) => (float) $value);
    }
}
