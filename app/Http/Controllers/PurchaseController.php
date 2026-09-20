<?php

namespace App\Http\Controllers;

use App\Exceptions\StaleDocumentException;
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

        if ($error = $this->businessRuleError($data)) {
            return back()->withErrors($error)->withInput();
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

    public function edit(Purchase $purchase)
    {
        if ($purchase->isCancelled()) {
            return redirect()->route('purchases.show', $purchase)->with('error', 'İptal edilmiş bir alış düzenlenemez.');
        }

        $purchase->load(['items.product', 'paymentAccountTransaction', 'cashTransaction']);

        // Only the purchase's own initial payment is editable; payments made
        // later through the cari screen are preserved by PurchaseService::update().
        $initialPaidAmount = (float) ($purchase->paymentAccountTransaction?->amount ?? $purchase->cashTransaction?->amount ?? 0);

        // An edited purchase's own products/supplier stay selectable even if deactivated.
        $products = Product::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $purchase->items->pluck('product_id')))
            ->orderBy('name')->get();
        $accounts = Account::where(fn ($q) => $q->where('active', true)->orWhere('id', $purchase->account_id))
            ->whereIn('type', ['supplier', 'other'])->orderBy('name')->get();
        $minimumQuantities = $this->purchaseService->minimumQuantities($purchase);
        $version = $purchase->versionToken();

        return view('purchases.edit', compact('purchase', 'products', 'accounts', 'initialPaidAmount', 'minimumQuantities', 'version'));
    }

    public function update(Request $request, Purchase $purchase)
    {
        $data = $this->validated($request, forUpdate: true);

        if ($error = $this->businessRuleError($data)) {
            return back()->withErrors($error)->withInput();
        }

        try {
            $this->purchaseService->update($purchase, $data, $request->user(), $data['_version']);
        } catch (StaleDocumentException $e) {
            // Nothing was saved: reload the current state instead of keeping stale input.
            return redirect()->route('purchases.edit', $purchase)->with('error', $e->getMessage());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'Alış güncellendi.');
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

    /**
     * @return array<string, string>|null field => message, or null when fine
     */
    private function businessRuleError(array $data): ?array
    {
        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['account_id'])) {
            return ['account_id' => 'Vadeli veya kısmi ödemeli alışlarda cari seçimi zorunludur.'];
        }

        if (in_array($data['payment_type'], ['vadeli', 'kismi']) && empty($data['due_date'])) {
            return ['due_date' => 'Vadeli veya kısmi ödemeli alışlarda vade tarihi zorunludur.'];
        }

        if (! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);

            if (! in_array($account->type, ['supplier', 'other'])) {
                return ['account_id' => 'Alış için tedarikçi veya diğer tipinde bir cari seçilmelidir.'];
            }
        }

        return null;
    }

    private function validated(Request $request, bool $forUpdate = false): array
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
            // Version the edit form was opened with (Purchase::versionToken()).
            '_version' => $forUpdate ? ['required', 'string'] : ['nullable'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
