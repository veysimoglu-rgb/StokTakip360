<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Support\Currency;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Read-only report: monetary value per movement = quantity × the movement's own
     * recorded unit_price. Never reads the product's current price. Movements without
     * a recorded currency (legacy data) are shown individually but excluded from the
     * per-currency subtotal, since their currency cannot be known retroactively.
     */
    public function movementValue(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);

        $movements = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->with(['product.category', 'account'])
            ->orderByDesc('movement_date')
            ->paginate(25)
            ->withQueryString();

        $qtyTotals = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->selectRaw('type, SUM(quantity) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $totalsByCurrency = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->whereNotNull('currency')
            ->where('unit_price', '>', 0)
            ->selectRaw('currency, type, SUM(quantity * unit_price) as total')
            ->groupBy('currency', 'type')
            ->get()
            ->groupBy('currency');

        $productSummary = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->where('stock_movements.type', 'out')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->selectRaw('products.id as product_id, products.name, products.code, products.category_id, stock_movements.currency,
                SUM(stock_movements.quantity) as qty_out,
                SUM(CASE WHEN stock_movements.unit_price > 0 THEN stock_movements.quantity * stock_movements.unit_price ELSE 0 END) as amount')
            ->groupBy('products.id', 'products.name', 'products.code', 'products.category_id', 'stock_movements.currency')
            ->orderByDesc('qty_out')
            ->get();

        $categoryIds = $productSummary->pluck('category_id')->filter()->unique();
        $categoryNames = Category::whereIn('id', $categoryIds)->pluck('name', 'id');

        $products = Product::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $accounts = Account::orderBy('name')->get();

        return view('reports.movement-value', compact(
            'movements', 'qtyTotals', 'totalsByCurrency', 'productSummary', 'categoryNames',
            'products', 'categories', 'accounts',
            'dateFrom', 'dateTo', 'period'
        ));
    }

    public function movementValueExport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $movements = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->with(['product.category'])
            ->orderByDesc('movement_date')
            ->get();

        $filename = 'stok-hareket-degeri-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($movements) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, ['Tarih', 'Ürün', 'Kod', 'Kategori', 'Tip', 'Miktar', 'Birim Fiyat', 'Para Birimi', 'Tutar']);

            foreach ($movements as $movement) {
                fputcsv($handle, [
                    $movement->movement_date->format('d.m.Y H:i'),
                    $movement->product->name,
                    $movement->product->code,
                    $movement->product->category?->name ?? '-',
                    $movement->type === 'in' ? 'Giriş' : 'Çıkış',
                    Quantity::format($movement->quantity),
                    $movement->amount() === null ? '' : number_format($movement->unit_price, 2, ',', '.'),
                    $movement->currency ?? 'Bilinmiyor',
                    $movement->amount() === null ? '' : number_format($movement->amount(), 2, ',', '.'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function movementValuePrint(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $movements = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->with(['product.category', 'account'])
            ->orderByDesc('movement_date')
            ->get();

        $qtyTotals = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->selectRaw('type, SUM(quantity) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $totalsByCurrency = $this->filteredMovements($request, $dateFrom, $dateTo)
            ->whereNotNull('currency')
            ->where('unit_price', '>', 0)
            ->selectRaw('currency, type, SUM(quantity * unit_price) as total')
            ->groupBy('currency', 'type')
            ->get()
            ->groupBy('currency');

        return view('reports.movement-value-print', compact('movements', 'qtyTotals', 'totalsByCurrency', 'dateFrom', 'dateTo'));
    }

    /**
     * Sales report: the list shows every sale in range (cancelled included,
     * with a badge); the totals are computed from a separately filtered
     * query that excludes cancelled sales — the same list/aggregate split
     * used by movementValue()/filteredMovements() above.
     */
    public function salesReport(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);

        $sales = $this->filteredSales($request, $dateFrom, $dateTo)
            ->with(['account', 'user'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $totalsByCurrency = $this->documentTotalsByCurrency($this->filteredSales($request, $dateFrom, $dateTo));

        $accounts = Account::orderBy('name')->get();

        return view('reports.sales', compact('sales', 'totalsByCurrency', 'accounts', 'dateFrom', 'dateTo', 'period'));
    }

    public function salesReportExport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $sales = $this->filteredSales($request, $dateFrom, $dateTo)
            ->with('account')
            ->orderByDesc('sale_date')
            ->get();

        $filename = 'satis-raporu-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($sales) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, ['Satış No', 'Tarih', 'Cari', 'Ödeme Tipi', 'Ödeme Durumu', 'Para Birimi', 'Toplam', 'Ödenen', 'Kalan', 'Vade Tarihi', 'İptal Durumu']);

            foreach ($sales as $sale) {
                fputcsv($handle, $this->documentCsvRow($sale));
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function salesReportPrint(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $sales = $this->filteredSales($request, $dateFrom, $dateTo)
            ->with('account')
            ->orderByDesc('sale_date')
            ->get();

        $totalsByCurrency = $this->documentTotalsByCurrency($this->filteredSales($request, $dateFrom, $dateTo));

        return view('reports.sales-print', compact('sales', 'totalsByCurrency', 'dateFrom', 'dateTo'))
            ->with('title', 'Satış Raporu')
            ->with('numberColumn', 'Satış No');
    }

    /**
     * Purchases report — the exact mirror of salesReport() above.
     */
    public function purchasesReport(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);

        $purchases = $this->filteredPurchases($request, $dateFrom, $dateTo)
            ->with(['account', 'user'])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $totalsByCurrency = $this->documentTotalsByCurrency($this->filteredPurchases($request, $dateFrom, $dateTo));

        $accounts = Account::orderBy('name')->get();

        return view('reports.purchases', compact('purchases', 'totalsByCurrency', 'accounts', 'dateFrom', 'dateTo', 'period'));
    }

    public function purchasesReportExport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $purchases = $this->filteredPurchases($request, $dateFrom, $dateTo)
            ->with('account')
            ->orderByDesc('purchase_date')
            ->get();

        $filename = 'alis-raporu-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($purchases) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Alış No', 'Tarih', 'Cari', 'Ödeme Tipi', 'Ödeme Durumu', 'Para Birimi', 'Toplam', 'Ödenen', 'Kalan', 'Vade Tarihi', 'İptal Durumu']);

            foreach ($purchases as $purchase) {
                fputcsv($handle, $this->documentCsvRow($purchase));
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function purchasesReportPrint(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $purchases = $this->filteredPurchases($request, $dateFrom, $dateTo)
            ->with('account')
            ->orderByDesc('purchase_date')
            ->get();

        $totalsByCurrency = $this->documentTotalsByCurrency($this->filteredPurchases($request, $dateFrom, $dateTo));

        return view('reports.sales-print', ['sales' => $purchases, 'totalsByCurrency' => $totalsByCurrency, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo])
            ->with('title', 'Alış Raporu')
            ->with('numberColumn', 'Alış No');
    }

    /**
     * Account statement (cari ekstre): requires an account to be selected —
     * with none, the view renders an empty-state prompt, never an error.
     * The opening balance is the net debit/credit of every transaction
     * strictly before dateFrom; the running balance then walks forward
     * through the in-range rows in one pass. Deliberately not paginated —
     * a running balance can't be correctly resumed mid-page without
     * re-deriving the same opening-balance query per page, so the full
     * filtered set is loaded at once (V1 volumes are small per account).
     */
    public function accountStatement(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);
        $accounts = Account::orderBy('name')->get();

        $account = null;
        $currency = null;
        $currencyOptions = collect(Currency::LIST);
        $openingBalance = 0.0;
        $rows = collect();

        if ($request->account_id) {
            $account = Account::findOrFail($request->account_id);
            $currencyOptions = $this->accountCurrencyOptions($account);
            $currency = $this->resolveStatementCurrency($request, $currencyOptions);
            [$openingBalance, $rows] = $this->statementRows($account, $dateFrom, $dateTo, $currency);
        }

        return view('reports.account-statement', compact('account', 'accounts', 'currency', 'currencyOptions', 'openingBalance', 'rows', 'dateFrom', 'dateTo', 'period'));
    }

    public function accountStatementExport(Request $request)
    {
        if (! $request->account_id) {
            return back()->with('error', 'Lütfen önce bir cari seçin.');
        }

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        $account = Account::findOrFail($request->account_id);
        $currency = $this->resolveStatementCurrency($request, $this->accountCurrencyOptions($account));
        [$openingBalance, $rows] = $this->statementRows($account, $dateFrom, $dateTo, $currency);

        $filename = 'cari-ekstre-'.$account->code.'-'.$currency.'-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($rows, $openingBalance, $currency) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Tarih', 'İşlem Tipi', 'Belge No', 'Para Birimi', 'Borç', 'Alacak', 'Bakiye', 'Kaynak']);
            fputcsv($handle, ['', 'Açılış Bakiyesi', '', $currency, '', '', number_format($openingBalance, 2, ',', '.'), '']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['transaction']->transaction_date->format('d.m.Y'),
                    $row['transaction']->typeLabel(),
                    $row['documentNumber'] ?? '-',
                    $row['transaction']->currency,
                    $row['debit'] === null ? '' : number_format($row['debit'], 2, ',', '.'),
                    $row['credit'] === null ? '' : number_format($row['credit'], 2, ',', '.'),
                    number_format($row['balance'], 2, ',', '.'),
                    $row['sourceLabel'],
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function accountStatementPrint(Request $request)
    {
        if (! $request->account_id) {
            return back()->with('error', 'Lütfen önce bir cari seçin.');
        }

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        $account = Account::findOrFail($request->account_id);
        $currency = $this->resolveStatementCurrency($request, $this->accountCurrencyOptions($account));
        [$openingBalance, $rows] = $this->statementRows($account, $dateFrom, $dateTo, $currency);

        return view('reports.account-statement-print', compact('account', 'currency', 'openingBalance', 'rows', 'dateFrom', 'dateTo'));
    }

    /**
     * Currencies this account has any activity in, TL first when present,
     * falling back to the full Currency::LIST when the account has no
     * transactions yet (a brand-new account still needs a currency to view
     * an empty statement in).
     */
    private function accountCurrencyOptions(Account $account): Collection
    {
        $active = AccountTransaction::where('account_id', $account->id)->distinct()->pluck('currency');

        $options = collect(Currency::LIST)->filter(fn ($c) => $active->contains($c))->values();

        return $options->isEmpty() ? collect(Currency::LIST) : $options;
    }

    /**
     * The statement can only ever show one currency's opening/running
     * balance at a time (mixing them would produce exactly the meaningless
     * blended total this feature must never show) — an explicit ?currency=
     * wins when valid, otherwise the account's first currency option (TL-
     * first per accountCurrencyOptions()) is used.
     */
    private function resolveStatementCurrency(Request $request, Collection $options): string
    {
        if ($request->currency && in_array($request->currency, Currency::LIST, true)) {
            return $request->currency;
        }

        return $options->first() ?? 'TL';
    }

    /**
     * Cash report: totals are always in-sync with CashTransaction's own
     * reversal mechanism — a cancelled entry's reversal row carries the
     * cancellation's own transaction_date (set by CashTransaction::cancel()),
     * so it naturally lands in whichever date range it was cancelled in.
     * Nothing here special-cases cancelled rows; the existing model
     * behaviour already produces the correct net effect per range.
     */
    public function cashReport(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);

        $transactions = $this->filteredCash($request, $dateFrom, $dateTo)
            ->with(['account', 'user'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $totalsByCurrency = $this->cashTotalsByCurrency($request, $dateFrom, $dateTo);

        return view('reports.cash', compact('transactions', 'totalsByCurrency', 'dateFrom', 'dateTo', 'period'));
    }

    public function cashReportExport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $transactions = $this->filteredCash($request, $dateFrom, $dateTo)
            ->with(['account', 'user'])
            ->orderByDesc('transaction_date')
            ->get();

        $filename = 'kasa-raporu-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($transactions) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Tarih', 'Hareket Tipi', 'Açıklama', 'Cari', 'Para Birimi', 'Tutar', 'Kullanıcı', 'Durum']);

            foreach ($transactions as $transaction) {
                fputcsv($handle, [
                    $transaction->transaction_date->format('d.m.Y H:i'),
                    $transaction->typeLabel(),
                    $transaction->description ?? '-',
                    $transaction->account?->name ?? '-',
                    $transaction->currency,
                    ($transaction->direction === 'in' ? '+' : '-').number_format($transaction->amount, 2, ',', '.'),
                    $transaction->user?->name ?? '-',
                    $transaction->isCancelled() ? 'İptal Edildi' : ($transaction->reversal_of_id ? 'Ters Kayıt' : '-'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function cashReportPrint(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $transactions = $this->filteredCash($request, $dateFrom, $dateTo)
            ->with(['account', 'user'])
            ->orderByDesc('transaction_date')
            ->get();

        $totalsByCurrency = $this->cashTotalsByCurrency($request, $dateFrom, $dateTo);

        return view('reports.cash-print', compact('transactions', 'totalsByCurrency', 'dateFrom', 'dateTo'));
    }

    /**
     * In/out/net per currency for the cash report — grouped by currency AND
     * direction in one query, then folded into {in, out, net} per currency.
     * Reversal rows are ordinary CashTransaction rows dated at their own
     * cancellation time, so they fall into whichever range/currency bucket
     * they naturally belong to — no special-casing needed here.
     */
    private function cashTotalsByCurrency(Request $request, string $dateFrom, string $dateTo): Collection
    {
        $rows = $this->filteredCash($request, $dateFrom, $dateTo)
            ->selectRaw('currency, direction, SUM(amount) as total')
            ->groupBy('currency', 'direction')
            ->get()
            ->groupBy('currency');

        return $rows->map(function ($group) {
            $in = (float) ($group->firstWhere('direction', 'in')->total ?? 0);
            $out = (float) ($group->firstWhere('direction', 'out')->total ?? 0);

            return ['in' => $in, 'out' => $out, 'net' => $in - $out];
        });
    }

    /**
     * Profitability report: built entirely from sale_items.cost_price (the
     * WP-9a snapshot) and sale_items.line_total — never FIFO, never a
     * weighted average, never a new cost engine. Cancelled sales are
     * excluded at the query root (same ->whereNull('cancelled_at')
     * convention as every other report). Lines with cost_price IS NULL
     * still count toward revenue/quantity (a sale's income is real even
     * when its cost is unknown) but are strictly excluded from cost/profit —
     * NULL cost is never treated as 0.
     */
    public function profitability(Request $request)
    {
        [$dateFrom, $dateTo, $period] = $this->resolveDateRange($request);

        $summaryByCurrency = $this->profitabilitySummaryByCurrency($this->filteredSaleItemsForProfitability($request, $dateFrom, $dateTo));
        $productPerformanceByCurrency = $this->profitabilityByProduct($this->filteredSaleItemsForProfitability($request, $dateFrom, $dateTo));

        $products = Product::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('reports.profitability', compact(
            'summaryByCurrency', 'productPerformanceByCurrency', 'products', 'categories', 'dateFrom', 'dateTo', 'period'
        ));
    }

    public function profitabilityExport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $productPerformanceByCurrency = $this->profitabilityByProduct($this->filteredSaleItemsForProfitability($request, $dateFrom, $dateTo));

        $filename = 'karlilik-raporu-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($productPerformanceByCurrency) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Para Birimi', 'Ürün', 'Kod', 'Miktar', 'Satış Tutarı', 'Maliyet', 'Brüt Kâr', 'Kâr Marjı (%)']);

            foreach ($productPerformanceByCurrency as $currency => $rows) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $currency,
                        $row->product_name,
                        $row->product_code,
                        $row->qty,
                        number_format($row->revenue, 2, ',', '.'),
                        $row->has_cost ? number_format($row->cost, 2, ',', '.') : 'Maliyet bilgisi yok',
                        $row->profit === null ? '—' : number_format($row->profit, 2, ',', '.'),
                        $row->margin === null ? '—' : number_format($row->margin, 1, ',', '.'),
                    ]);
                }
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function profitabilityPrint(Request $request)
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $summaryByCurrency = $this->profitabilitySummaryByCurrency($this->filteredSaleItemsForProfitability($request, $dateFrom, $dateTo));
        $productPerformanceByCurrency = $this->profitabilityByProduct($this->filteredSaleItemsForProfitability($request, $dateFrom, $dateTo));

        return view('reports.profitability-print', compact('summaryByCurrency', 'productPerformanceByCurrency', 'dateFrom', 'dateTo'));
    }

    /**
     * Shared filter base for the profitability report: date range (on the
     * sale's own date, not the line), currency, product, category — plus
     * the non-negotiable ->whereNull('sales.cancelled_at'). Returns a query
     * builder so summary/product aggregates each apply their own SELECT.
     */
    private function filteredSaleItemsForProfitability(Request $request, string $dateFrom, string $dateTo)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereNull('sales.cancelled_at')
            ->whereDate('sales.sale_date', '>=', $dateFrom)
            ->whereDate('sales.sale_date', '<=', $dateTo)
            ->when($request->currency, fn ($q) => $q->where('sales.currency', $request->currency))
            ->when($request->product_id, fn ($q) => $q->where('sale_items.product_id', $request->product_id))
            ->when($request->category_id, fn ($q) => $q->where('products.category_id', $request->category_id));
    }

    /**
     * One row per currency: quantity/revenue always reflect every line
     * (a sale's income doesn't disappear just because its cost is
     * unknown); cost/profit/margin are computed strictly from the
     * cost-known subset — never inferring 0 for a NULL cost_price. margin
     * is profit ÷ the cost-known revenue (not total revenue), since profit
     * itself only ever reflects that subset; that ratio would otherwise be
     * silently diluted by revenue that contributed nothing to the profit
     * figure.
     */
    private function profitabilitySummaryByCurrency($query): Collection
    {
        return $query->selectRaw('
                sales.currency as currency,
                SUM(sale_items.quantity) as qty,
                SUM(sale_items.line_total) as revenue,
                SUM(CASE WHEN sale_items.cost_price IS NOT NULL THEN sale_items.line_total ELSE 0 END) as revenue_with_cost,
                SUM(CASE WHEN sale_items.cost_price IS NULL THEN sale_items.line_total ELSE 0 END) as revenue_without_cost,
                SUM(CASE WHEN sale_items.cost_price IS NULL THEN sale_items.quantity ELSE 0 END) as qty_without_cost,
                SUM(CASE WHEN sale_items.cost_price IS NOT NULL THEN sale_items.cost_price * sale_items.quantity ELSE 0 END) as cost
            ')
            ->groupBy('sales.currency')
            ->get()
            ->keyBy('currency')
            ->map(function ($row) {
                $row->revenue_with_cost = (float) $row->revenue_with_cost;
                $row->cost = (float) $row->cost;
                $row->profit = $row->revenue_with_cost > 0 ? $row->revenue_with_cost - $row->cost : null;
                $row->margin = ($row->revenue_with_cost > 0 && $row->profit !== null)
                    ? ($row->profit / $row->revenue_with_cost) * 100
                    : null;

                return $row;
            });
    }

    /**
     * Product × currency rows — never one flat list mixing currencies
     * together, so sorting by profit later can never rank a USD figure
     * against a TL one. has_cost distinguishes "no cost data at all for
     * this product/currency" (show "Maliyet bilgisi yok") from "some lines
     * had cost data" (show the real partial figure, computed only from the
     * lines that actually had it).
     */
    private function profitabilityByProduct($query): Collection
    {
        $rows = $query->selectRaw('
                products.id as product_id,
                products.name as product_name,
                products.code as product_code,
                sales.currency as currency,
                SUM(sale_items.quantity) as qty,
                SUM(sale_items.line_total) as revenue,
                SUM(CASE WHEN sale_items.cost_price IS NOT NULL THEN sale_items.line_total ELSE 0 END) as revenue_with_cost,
                SUM(CASE WHEN sale_items.cost_price IS NOT NULL THEN sale_items.cost_price * sale_items.quantity ELSE 0 END) as cost,
                SUM(CASE WHEN sale_items.cost_price IS NOT NULL THEN 1 ELSE 0 END) as lines_with_cost
            ')
            ->groupBy('products.id', 'products.name', 'products.code', 'sales.currency')
            ->get()
            ->map(function ($row) {
                $row->has_cost = (int) $row->lines_with_cost > 0;
                $row->revenue_with_cost = (float) $row->revenue_with_cost;
                $row->cost = (float) $row->cost;
                $row->profit = $row->has_cost ? $row->revenue_with_cost - $row->cost : null;
                $row->margin = ($row->has_cost && $row->revenue_with_cost > 0) ? ($row->profit / $row->revenue_with_cost) * 100 : null;

                return $row;
            });

        return $rows->groupBy('currency')
            ->map(fn ($group) => $group->sortByDesc(fn ($row) => $row->profit ?? -INF)->values());
    }

    /**
     * Applies every shared filter (type, product, category, account, currency,
     * date range) to a fresh StockMovement query. Called separately for each
     * aggregate so one filtered dataset backs the list, totals, and exports.
     */
    private function filteredMovements(Request $request, string $dateFrom, string $dateTo)
    {
        return StockMovement::query()
            ->when($request->type, fn ($q) => $q->where('stock_movements.type', $request->type))
            ->when($request->product_id, fn ($q) => $q->where('stock_movements.product_id', $request->product_id))
            ->when($request->category_id, fn ($q) => $q->whereHas('product', fn ($q) => $q->where('category_id', $request->category_id)))
            ->when($request->account_id, fn ($q) => $q->where('stock_movements.account_id', $request->account_id))
            ->when($request->currency, fn ($q) => $q->where('stock_movements.currency', $request->currency))
            ->whereDate('stock_movements.movement_date', '>=', $dateFrom)
            ->whereDate('stock_movements.movement_date', '<=', $dateTo);
    }

    /**
     * Shared filters for the sales report: account, payment status, overdue-
     * only, date range. Cancelled sales are never excluded here — the list
     * shows them; callers add ->whereNull('cancelled_at') themselves when
     * computing totals.
     */
    private function filteredSales(Request $request, string $dateFrom, string $dateTo)
    {
        return Sale::query()
            ->when($request->account_id, fn ($q) => $q->where('account_id', $request->account_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->currency, fn ($q) => $q->where('currency', $request->currency))
            ->whereDate('sale_date', '>=', $dateFrom)
            ->whereDate('sale_date', '<=', $dateTo);
    }

    /**
     * Purchases report filters — the mirror of filteredSales() above.
     */
    private function filteredPurchases(Request $request, string $dateFrom, string $dateTo)
    {
        return Purchase::query()
            ->when($request->account_id, fn ($q) => $q->where('account_id', $request->account_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->currency, fn ($q) => $q->where('currency', $request->currency))
            ->whereDate('purchase_date', '>=', $dateFrom)
            ->whereDate('purchase_date', '<=', $dateTo);
    }

    /**
     * Cash report filter: date range only, per spec — no account filter here.
     */
    private function filteredCash(Request $request, string $dateFrom, string $dateTo)
    {
        return CashTransaction::query()
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo);
    }

    /**
     * Sum(total)/Sum(paid)/Sum(remaining) grouped by currency, for an
     * already-filtered Sale or Purchase query — TL and USD/EUR totals are
     * never added together. Cancelled documents are always excluded here;
     * callers still show them in the list, just not in these totals.
     */
    private function documentTotalsByCurrency($query): Collection
    {
        return $query->whereNull('cancelled_at')
            ->selectRaw('currency, SUM(total) as total, SUM(paid_amount) as paid, SUM(total - paid_amount) as remaining')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');
    }

    /**
     * Shared CSV row shape for sales/purchases export — Sale and Purchase
     * expose the same label/amount API (paymentTypeLabel, statusLabel,
     * remaining, isCancelled), so one row-builder serves both reports.
     */
    private function documentCsvRow(Sale|Purchase $document): array
    {
        $date = $document instanceof Sale ? $document->sale_date : $document->purchase_date;

        return [
            $document->number,
            $date->format('d.m.Y'),
            $document->account?->name ?? 'Genel',
            $document->paymentTypeLabel(),
            $document->statusLabel(),
            $document->currency,
            number_format($document->total, 2, ',', '.'),
            number_format($document->paid_amount, 2, ',', '.'),
            number_format($document->remaining(), 2, ',', '.'),
            $document->due_date?->format('d.m.Y') ?? '-',
            $document->isCancelled() ? 'İptal Edildi' : '-',
        ];
    }

    /**
     * Builds the account statement's opening balance and its in-range rows
     * with a running balance walked forward in a single pass, for exactly
     * one currency — opening balance, running balance, debit and credit are
     * all scoped to it, so a USD and a TL transaction on the same account
     * are never added into the same running total. source is eager-loaded
     * polymorphically (Sale/Purchase/CashTransaction/null) so resolving each
     * row's document number never triggers an extra query.
     *
     * @return array{0: float, 1: Collection}
     */
    private function statementRows(Account $account, string $dateFrom, string $dateTo, string $currency): array
    {
        $openingBalance = (float) (AccountTransaction::where('account_id', $account->id)
            ->where('currency', $currency)
            ->where('transaction_date', '<', $dateFrom)
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as balance")
            ->value('balance') ?? 0);

        $transactions = AccountTransaction::where('account_id', $account->id)
            ->where('currency', $currency)
            ->with('source')
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $runningBalance = $openingBalance;

        $rows = $transactions->map(function ($transaction) use (&$runningBalance) {
            $amount = (float) $transaction->amount;
            $runningBalance += $transaction->direction === 'debit' ? $amount : -$amount;

            $documentNumber = match (true) {
                $transaction->source instanceof Sale, $transaction->source instanceof Purchase => $transaction->source->number,
                default => null,
            };

            $sourceLabel = match (true) {
                $transaction->source instanceof Sale => 'Satış',
                $transaction->source instanceof Purchase => 'Alış',
                $transaction->source instanceof CashTransaction => $transaction->typeLabel(),
                default => 'Manuel',
            };

            return [
                'transaction' => $transaction,
                'debit' => $transaction->direction === 'debit' ? $amount : null,
                'credit' => $transaction->direction === 'credit' ? $amount : null,
                'balance' => $runningBalance,
                'documentNumber' => $documentNumber,
                'sourceLabel' => $sourceLabel,
            ];
        });

        return [$openingBalance, $rows];
    }

    /**
     * @return array{0: string, 1: string, 2: string} [dateFrom, dateTo, activePeriod]
     */
    private function resolveDateRange(Request $request): array
    {
        $period = $request->get('period');

        $range = match ($period) {
            'today' => [Carbon::today(), Carbon::today()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'last_month' => [Carbon::now()->subMonthNoOverflow()->startOfMonth(), Carbon::now()->subMonthNoOverflow()->endOfMonth()],
            default => null,
        };

        if ($range) {
            return [$range[0]->toDateString(), $range[1]->toDateString(), $period];
        }

        if ($request->date_from || $request->date_to) {
            return [
                $request->date_from ?: Carbon::now()->startOfMonth()->toDateString(),
                $request->date_to ?: Carbon::now()->endOfMonth()->toDateString(),
                'custom',
            ];
        }

        return [
            Carbon::now()->startOfMonth()->toDateString(),
            Carbon::now()->endOfMonth()->toDateString(),
            'this_month',
        ];
    }
}
