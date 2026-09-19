<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class SaleController extends Controller
{
    public function __construct(private SaleService $saleService) {}

    public function index(Request $request)
    {
        $sales = Sale::with(['account', 'user'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('sales.index', compact('sales'));
    }

    public function create()
    {
        $products = Product::where('active', true)->orderBy('name')->get();
        $accounts = Account::where('active', true)->whereIn('type', ['customer', 'other'])->orderBy('name')->get();

        return view('sales.create', compact('products', 'accounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($error = $this->businessRuleError($data)) {
            return back()->withErrors($error)->withInput();
        }

        try {
            $sale = $this->saleService->create($data, $request->user());
        } catch (InsufficientStockException $e) {
            return back()->withInput()->with('stock_warning', $e->getMessage())->with('stock_shortages', $e->shortages);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', 'Sipariş kaydedildi.');
    }

    public function show(Sale $sale)
    {
        $sale->load(['account', 'user', 'items.product', 'debtAccountTransaction', 'paymentAccountTransaction', 'cashTransaction']);

        return view('sales.show', compact('sale'));
    }

    public function edit(Sale $sale)
    {
        if ($sale->isCancelled()) {
            return redirect()->route('sales.show', $sale)->with('error', 'İptal edilmiş bir sipariş düzenlenemez.');
        }

        $sale->load(['items.product', 'paymentAccountTransaction', 'cashTransaction']);

        // Only the order's own initial payment is editable; money collected
        // later through the cari screen is preserved by SaleService::update().
        $initialPaidAmount = (float) ($sale->paymentAccountTransaction?->amount ?? $sale->cashTransaction?->amount ?? 0);

        // An edited order's own products stay selectable even if deactivated.
        $products = Product::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $sale->items->pluck('product_id')))
            ->orderBy('name')->get();
        $accounts = Account::where(fn ($q) => $q->where('active', true)->orWhere('id', $sale->account_id))
            ->whereIn('type', ['customer', 'other'])->orderBy('name')->get();

        return view('sales.edit', compact('sale', 'products', 'accounts', 'initialPaidAmount'));
    }

    public function update(Request $request, Sale $sale)
    {
        $data = $this->validated($request);

        if ($error = $this->businessRuleError($data)) {
            return back()->withErrors($error)->withInput();
        }

        try {
            $this->saleService->update($sale, $data, $request->user());
        } catch (InsufficientStockException $e) {
            return back()->withInput()->with('stock_warning', $e->getMessage())->with('stock_shortages', $e->shortages);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', 'Sipariş güncellendi.');
    }

    public function receipt(Sale $sale)
    {
        $sale->load(['account', 'items.product']);

        return view('sales.receipt-print', compact('sale'));
    }

    public function cancel(Request $request, Sale $sale)
    {
        try {
            $this->saleService->cancel($sale, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', 'Sipariş iptal edildi.');
    }

    /**
     * @return array<string, string>|null field => message, or null when fine
     */
    private function businessRuleError(array $data): ?array
    {
        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['account_id'])) {
            return ['account_id' => 'Vadeli veya kısmi ödemeli siparişlerde cari seçimi zorunludur.'];
        }

        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['due_date'])) {
            return ['due_date' => 'Vadeli veya kısmi ödemeli siparişlerde vade tarihi zorunludur.'];
        }

        if (! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);

            if (! in_array($account->type, ['customer', 'other'])) {
                return ['account_id' => 'Sipariş için müşteri veya diğer tipinde bir cari seçilmelidir.'];
            }
        }

        return null;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'payment_type' => ['required', Rule::in(['pesin', 'vadeli', 'kismi'])],
            // min:0, not min:0.01 — the create form always submits a hidden
            // paid_amount=0 for a vadeli sale. SaleService::resolvePartialPayment()
            // separately rejects 0 for 'kismi', so this stays safe.
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'sale_date' => ['nullable', 'date'],
            // No after:today constraint — backdated due dates are allowed
            // (entering a historical vadeli sale with a past due date).
            'due_date' => ['nullable', 'date'],
            'confirm_insufficient_stock' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            // Up to 3 decimals (matches the decimal(14,3) columns).
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            'items.*.package_qty_input' => ['nullable', 'numeric', 'min:0.001', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $data['confirm_insufficient_stock'] = $request->boolean('confirm_insufficient_stock');

        return $data;
    }
}
