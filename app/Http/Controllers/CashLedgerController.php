<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashLedgerController extends Controller
{
    /**
     * Records a collection from a customer (or any account): a debt-reducing
     * account_transaction and a cash-increasing cash_transaction, created
     * together and cross-linked via the source morph. Either both rows exist
     * or neither does.
     *
     * Optionally, the collection can be applied against one specific open
     * Sale belonging to this account (WP-10e) — never more than one, never
     * chosen automatically. When applied, the Sale's own paid_amount/status
     * are updated in the same transaction so Sale::overdue() and every
     * report/dashboard figure that already reads paid_amount live reflect it
     * immediately, with no change to their own logic.
     */
    public function collect(Request $request, Account $account)
    {
        $data = $this->validated($request);
        $sale = $this->resolveAppliedDocument($request, $account, $data, Sale::class, 'sale_id');

        DB::transaction(function () use ($data, $request, $account, $sale) {
            $accountTransaction = $account->transactions()->create([
                'type' => 'collection',
                'direction' => AccountTransaction::TYPE_DIRECTIONS['collection'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'user_id' => $request->user()->id,
                'applies_to_type' => $sale ? Sale::class : null,
                'applies_to_id' => $sale?->id,
            ]);

            $cashTransaction = CashTransaction::create([
                'type' => 'collection',
                'direction' => CashTransaction::TYPE_DIRECTIONS['collection'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'account_id' => $account->id,
                'user_id' => $request->user()->id,
                'source_type' => AccountTransaction::class,
                'source_id' => $accountTransaction->id,
            ]);

            $accountTransaction->update([
                'source_type' => CashTransaction::class,
                'source_id' => $cashTransaction->id,
            ]);

            if ($sale) {
                $this->applyPaymentToDocument($sale, $data['amount']);
            }
        });

        return back()->with('success', 'Tahsilat kaydedildi.');
    }

    /**
     * Records a payment to a supplier (or any account): a debt-reducing
     * account_transaction and a cash-decreasing cash_transaction, created
     * together and cross-linked via the source morph.
     *
     * Mirrors collect() above: optionally applies the payment against one
     * specific open Purchase belonging to this account.
     */
    public function pay(Request $request, Account $account)
    {
        $data = $this->validated($request);
        $purchase = $this->resolveAppliedDocument($request, $account, $data, Purchase::class, 'purchase_id');

        DB::transaction(function () use ($data, $request, $account, $purchase) {
            $accountTransaction = $account->transactions()->create([
                'type' => 'payment',
                'direction' => AccountTransaction::TYPE_DIRECTIONS['payment'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'user_id' => $request->user()->id,
                'applies_to_type' => $purchase ? Purchase::class : null,
                'applies_to_id' => $purchase?->id,
            ]);

            $cashTransaction = CashTransaction::create([
                'type' => 'payment',
                'direction' => CashTransaction::TYPE_DIRECTIONS['payment'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'account_id' => $account->id,
                'user_id' => $request->user()->id,
                'source_type' => AccountTransaction::class,
                'source_id' => $accountTransaction->id,
            ]);

            $accountTransaction->update([
                'source_type' => CashTransaction::class,
                'source_id' => $cashTransaction->id,
            ]);

            if ($purchase) {
                $this->applyPaymentToDocument($purchase, $data['amount']);
            }
        });

        return back()->with('success', 'Ödeme kaydedildi.');
    }

    /**
     * currency defaults to 'TL' when the request omits it, so existing
     * callers (and the pre-multi-currency test suite) that never sent a
     * currency keep collecting/paying in TL exactly as before.
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', Rule::in(Currency::LIST)],
            'description' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['nullable', 'date'],
            'sale_id' => ['nullable', 'integer', 'exists:sales,id'],
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
        ]) + ['currency' => $request->input('currency', 'TL') ?: 'TL'];
    }

    /**
     * When the request names a document (sale_id/purchase_id), load it and
     * verify — server-side, regardless of what the form offered — that it
     * genuinely belongs to this account, is still open, matches the
     * collection/payment's own currency, and that the amount does not
     * exceed what is actually left on it. Returns null when no document was
     * selected at all, which is the existing free-standing behavior.
     */
    private function resolveAppliedDocument(Request $request, Account $account, array $data, string $modelClass, string $field): Sale|Purchase|null
    {
        $id = $request->input($field);

        if (! $id) {
            return null;
        }

        /** @var Sale|Purchase $document */
        $document = $modelClass::findOrFail($id);
        $label = $modelClass === Sale::class ? 'satış' : 'alış';

        if ((int) $document->account_id !== $account->id) {
            throw ValidationException::withMessages([$field => "Seçilen {$label} bu cariye ait değil."]);
        }

        if ($document->isCancelled()) {
            throw ValidationException::withMessages([$field => "Seçilen {$label} iptal edilmiş."]);
        }

        if ($document->currency !== $data['currency']) {
            throw ValidationException::withMessages([$field => "Seçilen {$label} ile para birimi uyuşmuyor."]);
        }

        if ($document->remaining() <= 0) {
            throw ValidationException::withMessages([$field => "Seçilen {$label} zaten tamamen ödenmiş."]);
        }

        if ((float) $data['amount'] > $document->remaining()) {
            throw ValidationException::withMessages([$field => "Tutar, seçilen {$label} belgesinin kalan tutarını aşamaz."]);
        }

        return $document;
    }

    /**
     * Applies an already-validated amount to a Sale/Purchase's paid_amount
     * and recomputes its status the same way payment status is always
     * derived (0 remaining -> paid, otherwise partial — never unpaid, since
     * this only ever adds to an already-open, positively-paid-or-zero
     * document). Sale::overdue()/Purchase::overdue() and every dashboard/
     * report figure read these two columns live, so nothing else needs to
     * be touched for them to reflect this immediately.
     */
    private function applyPaymentToDocument(Sale|Purchase $document, float $amount): void
    {
        $document->increment('paid_amount', $amount);
        $document->refresh();

        $document->update([
            'status' => $document->remaining() <= 0 ? 'paid' : 'partial',
        ]);
    }
}
