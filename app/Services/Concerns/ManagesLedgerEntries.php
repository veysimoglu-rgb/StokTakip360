<?php

namespace App\Services\Concerns;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Shared building blocks for SaleService/PurchaseService: locking product
 * rows before a stock check, and creating the AccountTransaction/
 * CashTransaction legs that record a sale/purchase against a cari and the
 * kasa. Every created row is tagged with source_type/source_id pointing at
 * the Sale/Purchase, reusing the morph infrastructure from WP-2/3.
 */
trait ManagesLedgerEntries
{
    /**
     * Locks every distinct product referenced by the line items, in
     * ascending product_id order, so two concurrent multi-item sales never
     * lock the same rows in a different order (deadlock avoidance).
     */
    protected function lockProducts(array $items): Collection
    {
        $productIds = collect($items)->pluck('product_id')->unique()->sort()->values();

        return Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Creates the debt-side row (type: sale/purchase, always debit) for the
     * full document total, tagged with source = the Sale/Purchase itself.
     * $currency is always the document's own currency — never chosen
     * independently — so the debt leg can never drift from what the Sale/
     * Purchase itself is denominated in.
     */
    protected function createDebtTransaction(Account $account, string $type, float $amount, Model $source, ?int $userId, $date, string $currency): AccountTransaction
    {
        return $account->transactions()->create([
            'type' => $type,
            'direction' => AccountTransaction::TYPE_DIRECTIONS[$type],
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => $date,
            'user_id' => $userId,
            'source_type' => $source::class,
            'source_id' => $source->id,
        ]);
    }

    /**
     * Creates the paid-portion pair (AccountTransaction credit + matching
     * CashTransaction) for a sale/purchase that has an account attached.
     * Both rows point at the Sale/Purchase directly (not at each other) —
     * the document itself is the authoritative link used for cancellation.
     * Both rows always carry the document's own currency.
     */
    protected function createPaymentLeg(Account $account, string $type, float $amount, Model $source, ?int $userId, $date, string $currency): array
    {
        $accountTransaction = $account->transactions()->create([
            'type' => $type,
            'direction' => AccountTransaction::TYPE_DIRECTIONS[$type],
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => $date,
            'user_id' => $userId,
            'source_type' => $source::class,
            'source_id' => $source->id,
        ]);

        $cashTransaction = CashTransaction::create([
            'type' => $type,
            'direction' => CashTransaction::TYPE_DIRECTIONS[$type],
            'amount' => $amount,
            'currency' => $currency,
            'account_id' => $account->id,
            'transaction_date' => $date,
            'user_id' => $userId,
            'source_type' => $source::class,
            'source_id' => $source->id,
        ]);

        return [$accountTransaction, $cashTransaction];
    }

    /**
     * Creates a bare cash movement for a paid sale/purchase with no account
     * attached (an anonymous cash sale/purchase) — no AccountTransaction is
     * created since there is no cari to post a debt against.
     */
    protected function createCashOnlyLeg(string $type, float $amount, Model $source, ?int $userId, $date, string $currency): CashTransaction
    {
        return CashTransaction::create([
            'type' => $type,
            'direction' => CashTransaction::TYPE_DIRECTIONS[$type],
            'amount' => $amount,
            'currency' => $currency,
            'account_id' => null,
            'transaction_date' => $date,
            'user_id' => $userId,
            'source_type' => $source::class,
            'source_id' => $source->id,
        ]);
    }
}
