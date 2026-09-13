<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashLedgerController extends Controller
{
    /**
     * Records a collection from a customer (or any account): a debt-reducing
     * account_transaction and a cash-increasing cash_transaction, created
     * together and cross-linked via the source morph. Either both rows exist
     * or neither does.
     */
    public function collect(Request $request, Account $account)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request, $account) {
            $accountTransaction = $account->transactions()->create([
                'type' => 'collection',
                'direction' => AccountTransaction::TYPE_DIRECTIONS['collection'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'user_id' => $request->user()->id,
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
        });

        return back()->with('success', 'Tahsilat kaydedildi.');
    }

    /**
     * Records a payment to a supplier (or any account): a debt-reducing
     * account_transaction and a cash-decreasing cash_transaction, created
     * together and cross-linked via the source morph.
     */
    public function pay(Request $request, Account $account)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request, $account) {
            $accountTransaction = $account->transactions()->create([
                'type' => 'payment',
                'direction' => AccountTransaction::TYPE_DIRECTIONS['payment'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? now(),
                'user_id' => $request->user()->id,
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
        ]) + ['currency' => $request->input('currency', 'TL') ?: 'TL'];
    }
}
