<?php

namespace App\Http\Controllers;

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

        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['account_id'])) {
            return back()->withErrors(['account_id' => 'Vadeli veya kısmi ödemeli satışlarda cari seçimi zorunludur.'])->withInput();
        }

        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['due_date'])) {
            return back()->withErrors(['due_date' => 'Vadeli veya kısmi ödemeli satışlarda vade tarihi zorunludur.'])->withInput();
        }

        if (! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);

            if (! in_array($account->type, ['customer', 'other'])) {
                return back()->withErrors(['account_id' => 'Satış için müşteri veya diğer tipinde bir cari seçilmelidir.'])->withInput();
            }
        }

        try {
            $sale = $this->saleService->create($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', 'Satış kaydedildi.');
    }

    public function show(Sale $sale)
    {
        $sale->load(['account', 'user', 'items.product', 'debtAccountTransaction', 'paymentAccountTransaction', 'cashTransaction']);

        return view('sales.show', compact('sale'));
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

        return redirect()->route('sales.show', $sale)->with('success', 'Satış iptal edildi.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
