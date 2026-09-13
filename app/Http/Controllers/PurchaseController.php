<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $purchaseService) {}

    public function index(Request $request)
    {
        $purchases = Purchase::with(['account', 'user'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('purchases.index', compact('purchases'));
    }

    public function create()
    {
        $products = Product::where('active', true)->orderBy('name')->get();
        $accounts = Account::where('active', true)->whereIn('type', ['supplier', 'other'])->orderBy('name')->get();

        return view('purchases.create', compact('products', 'accounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['account_id'])) {
            return back()->withErrors(['account_id' => 'Vadeli veya kısmi ödemeli alışlarda cari seçimi zorunludur.'])->withInput();
        }

        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['due_date'])) {
            return back()->withErrors(['due_date' => 'Vadeli veya kısmi ödemeli alışlarda vade tarihi zorunludur.'])->withInput();
        }

        if (! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);

            if (! in_array($account->type, ['supplier', 'other'])) {
                return back()->withErrors(['account_id' => 'Alış için tedarikçi veya diğer tipinde bir cari seçilmelidir.'])->withInput();
            }
        }

        try {
            $purchase = $this->purchaseService->create($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'Alış kaydedildi.');
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['account', 'user', 'items.product', 'debtAccountTransaction', 'paymentAccountTransaction', 'cashTransaction']);

        return view('purchases.show', compact('purchase'));
    }

    public function cancel(Request $request, Purchase $purchase)
    {
        try {
            $this->purchaseService->cancel($purchase, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'Alış iptal edildi.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'account_id' => ['nullable', 'exists:accounts,id'],
            'payment_type' => ['required', Rule::in(['pesin', 'vadeli', 'kismi'])],
            // min:0, not min:0.01 — the create form always submits a hidden
            // paid_amount=0 for a vadeli purchase. PurchaseService::resolvePartialPayment()
            // separately rejects 0 for 'kismi', so this stays safe.
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            // No after:today constraint — backdated due dates are allowed
            // (entering a historical vadeli purchase with a past due date).
            'due_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
