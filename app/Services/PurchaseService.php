<?php

namespace App\Services;

use App\Exceptions\StaleDocumentException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Concerns\ManagesLedgerEntries;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseService
{
    use ManagesLedgerEntries;

    /**
     * Creates a Purchase with its items, stock movements, and (depending on
     * account_id/payment_type) the account_transaction/cash_transaction
     * legs — all inside one DB::transaction. A purchase always increases
     * stock, so there is no insufficient-stock guard on the create path
     * (only on cancellation, see StockMovement::cancel()).
     */
    public function create(array $data, User $user): Purchase
    {
        return DB::transaction(function () use ($data, $user) {
            $products = $this->lockProducts($data['items']);

            $document = $this->prepareDocument($data, $products);
            [$paidAmount, $status] = $this->resolvePayment($data['payment_type'], $document['total'], $data['paid_amount'] ?? null);

            $purchaseDate = $data['purchase_date'] ?? now();

            $purchase = Purchase::create([
                'number' => Purchase::generateNumber(),
                'account_id' => $data['account_id'] ?? null,
                'payment_type' => $data['payment_type'],
                'subtotal' => $document['subtotal'],
                'discount_total' => $document['discount'],
                'total' => $document['total'],
                'paid_amount' => $paidAmount,
                'currency' => $document['currency'],
                'status' => $status,
                'note' => $data['note'] ?? null,
                'purchase_date' => $purchaseDate,
                'due_date' => $data['due_date'] ?? null,
                'user_id' => $user->id,
            ]);

            $this->applyLines($purchase, $document['lines'], $user, $purchaseDate, $document['currency']);
            $this->postLedgerLegs($purchase, $document['total'], $paidAmount, $user, $purchaseDate, $document['currency']);

            return $purchase->refresh();
        });
    }

    /**
     * Re-opens a saved purchase with the app's immutable-ledger pattern: the
     * old stock/debt/payment/cash rows are closed with reversals (never
     * edited or deleted) and fresh rows are written for the new content. The
     * Purchase header (id, number, original date) stays.
     *
     * Unlike a sale, reversing a purchase's stock entry can be refused (the
     * stock may already have been sold). So the NEW stock entries are created
     * first and the OLD ones reversed afterwards: StockMovement::cancel()'s
     * "current stock >= quantity" guard then holds exactly when the resulting
     * stock is non-negative, i.e. only the real difference matters. A
     * pre-check turns a violation into a clear message before anything is
     * written; any failure rolls the whole transaction back.
     *
     * @param  string|null  $expectedVersion  Purchase::versionToken() the edit form was opened with
     */
    public function update(Purchase $purchase, array $data, User $user, ?string $expectedVersion = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $data, $user, $expectedVersion) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->isCancelled()) {
                throw new RuntimeException('İptal edilmiş bir alış düzenlenemez.');
            }

            if ($expectedVersion !== null && ! hash_equals($purchase->versionToken(), $expectedVersion)) {
                throw new StaleDocumentException('Bu alış siz düzenlerken değişti (başka bir kullanıcı düzenledi veya ödeme yaptı). Değişiklikleriniz kaydedilmedi; güncel hâli yüklendi.');
            }

            $oldItems = $purchase->items()->get();

            // Lock the rows this edit will close, then every product involved
            // (old + new lines) in id order.
            $legIds = array_filter([$purchase->debt_account_transaction_id, $purchase->payment_account_transaction_id]);
            $accountLegs = $legIds ? AccountTransaction::whereIn('id', $legIds)->lockForUpdate()->get() : collect();
            $cashLeg = $purchase->cash_transaction_id ? CashTransaction::lockForUpdate()->find($purchase->cash_transaction_id) : null;
            $oldMovements = StockMovement::whereIn('id', $oldItems->pluck('stock_movement_id')->filter()->all())->lockForUpdate()->get()->keyBy('id');

            $lockItems = collect($oldItems->map(fn ($i) => ['product_id' => $i->product_id]))->merge($data['items'])->all();
            $products = $this->lockProducts($lockItems);

            $document = $this->prepareDocument($data, $products);

            // Payments made later through the cari screen and applied to this
            // purchase are not part of its own payment leg — they are kept.
            $laterPayments = (float) AccountTransaction::where('applies_to_type', Purchase::class)
                ->where('applies_to_id', $purchase->id)
                ->where('type', 'payment')
                ->whereNull('cancelled_at')
                ->whereNull('reversal_of_id')
                ->sum('amount');

            if ($laterPayments > 0) {
                if ((int) ($data['account_id'] ?? 0) !== (int) $purchase->account_id) {
                    throw new RuntimeException('Bu alışa sonradan yapılan ödemeler var; tedarikçi değiştirilemez. Önce ilgili ödemeleri iptal edin.');
                }

                if ($document['currency'] !== $purchase->currency) {
                    throw new RuntimeException('Bu alışa sonradan yapılan ödemeler var; para birimi değiştirilemez. Önce ilgili ödemeleri iptal edin.');
                }
            }

            $this->assertStockCanBeReduced($oldItems, $document['lines'], $products);

            [$initialPayment] = $this->resolvePayment($data['payment_type'], $document['total'], $data['paid_amount'] ?? null);
            $paidAmount = round($initialPayment + $laterPayments, 2);

            if ($paidAmount > $document['total'] + 0.00001) {
                throw new RuntimeException('Bu alışa sonradan yapılan ödemeler ('.number_format($laterPayments, 2, ',', '.').') yeni alış toplamından fazla olacak. Önce ilgili ödemeyi iptal edin.');
            }

            $status = match (true) {
                $paidAmount >= $document['total'] - 0.00001 => 'paid',
                $paidAmount > 0 => 'partial',
                default => 'unpaid',
            };

            $reason = 'Alış düzenlendi: '.$purchase->number;
            $date = $purchase->purchase_date;

            // 1) new stock entries first, 2) then close the old ones
            $this->applyLines($purchase, $document['lines'], $user, $date, $document['currency']);

            foreach ($oldItems as $oldItem) {
                $oldMovements->get($oldItem->stock_movement_id)?->cancel($user, $reason);
            }

            PurchaseItem::whereIn('id', $oldItems->pluck('id')->all())->delete();

            // 3) cari/kasa: reverse the old legs, then write new ones
            foreach ($accountLegs as $leg) {
                $leg->cancel($user, $reason);
            }

            $cashLeg?->cancel($user, $reason);

            $purchase->forceFill([
                'account_id' => $data['account_id'] ?? null,
                'payment_type' => $data['payment_type'],
                'subtotal' => $document['subtotal'],
                'discount_total' => $document['discount'],
                'total' => $document['total'],
                'paid_amount' => $paidAmount,
                'currency' => $document['currency'],
                'status' => $status,
                'note' => $data['note'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'edited_at' => now(),
                'debt_account_transaction_id' => null,
                'payment_account_transaction_id' => null,
                'cash_transaction_id' => null,
            ])->save();

            $this->postLedgerLegs($purchase, $document['total'], $initialPayment, $user, $date, $document['currency']);

            return $purchase->refresh();
        });
    }

    /**
     * Reverses every child row atomically, then marks the Purchase itself
     * cancelled. If the stock this purchase added has already been
     * consumed, StockMovement::cancel() refuses the reversal and the whole
     * cancellation rolls back (nothing is left half-cancelled).
     */
    public function cancel(Purchase $purchase, User $user): void
    {
        if ($purchase->isCancelled()) {
            throw new RuntimeException('Bu alış zaten iptal edilmiş.');
        }

        DB::transaction(function () use ($purchase, $user) {
            $reason = 'Alış iptali: '.$purchase->number;

            foreach ($purchase->items as $item) {
                $item->stockMovement?->cancel($user, $reason);
            }

            $purchase->debtAccountTransaction?->cancel($user, $reason);
            $purchase->paymentAccountTransaction?->cancel($user, $reason);
            $purchase->cashTransaction?->cancel($user, $reason);

            $purchase->forceFill(['cancelled_at' => now()])->save();
        });
    }

    /**
     * Per product: the smallest quantity this purchase may keep, given that
     * part of the stock it added has already been used. Advisory (read
     * without locks) — update() enforces it. Only products with a minimum > 0.
     *
     * @return array<int, int>
     */
    public function minimumQuantities(Purchase $purchase): array
    {
        $purchase->loadMissing('items');
        $products = Product::whereIn('id', $purchase->items->pluck('product_id')->unique())->get()->keyBy('id');

        return $purchase->items->groupBy('product_id')
            ->map(fn ($rows, $productId) => max(0, (int) ceil((float) $rows->sum('quantity') - (float) $products[$productId]->current_stock)))
            ->filter(fn ($min) => $min > 0)
            ->all();
    }

    /**
     * Validates and prices every line against the (already locked) products.
     *
     * @return array{lines: array, subtotal: float|int, discount: float|int, total: float|int, currency: string}
     */
    private function prepareDocument(array $data, Collection $products): array
    {
        $subtotal = 0;
        $lines = [];
        $documentCurrency = null;

        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw new RuntimeException('Seçilen ürün bulunamadı.');
            }

            if ($documentCurrency === null) {
                $documentCurrency = $product->currency;
            } elseif ($product->currency !== $documentCurrency) {
                throw new RuntimeException("Bir alışta tüm ürünler aynı para birimine ait olmalıdır: {$documentCurrency} ile {$product->currency} karıştırılamaz.");
            }

            $lineTotal = $item['quantity'] * $item['unit_price'];
            $subtotal += $lineTotal;

            $lines[] = [
                'product' => $product,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $lineTotal,
            ];
        }

        $discountTotal = $data['discount_total'] ?? 0;

        if ($discountTotal > $subtotal) {
            throw new RuntimeException('İskonto tutarı ara toplamdan büyük olamaz.');
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discountTotal,
            'total' => $subtotal - $discountTotal,
            'currency' => $documentCurrency,
        ];
    }

    /**
     * Clear pre-check for an edit: for every product the old purchase added,
     * (current stock + new quantity - old quantity) must stay >= 0 — the
     * exact condition under which reversing the old entries is allowed.
     */
    private function assertStockCanBeReduced(Collection $oldItems, array $newLines, Collection $products): void
    {
        $newByProduct = collect($newLines)->groupBy(fn ($line) => $line['product']->id)
            ->map(fn ($rows) => (float) collect($rows)->sum('quantity'));

        foreach ($oldItems->groupBy('product_id') as $productId => $rows) {
            $product = $products->get($productId);
            $old = (float) $rows->sum('quantity');
            $new = (float) ($newByProduct[$productId] ?? 0);
            $stock = (float) $product->current_stock;

            if ($stock + $new - $old < -0.0005) {
                throw new RuntimeException(sprintf(
                    'Stok yetersiz: %s ürününden bu alışla girilen %s adedin bir kısmı zaten kullanılmış (mevcut stok: %s). Bu alışın bu ürün kalemi en az %s adet olmalı.',
                    $product->name,
                    Quantity::format($old),
                    Quantity::format($stock),
                    Quantity::format($old - $stock)
                ));
            }
        }
    }

    /**
     * Creates the PurchaseItem rows with their stock 'in' movements and the
     * stock increments.
     */
    private function applyLines(Purchase $purchase, array $lines, User $user, $date, string $currency): void
    {
        foreach ($lines as $line) {
            $purchaseItem = PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
            ]);

            $movement = StockMovement::create([
                'product_id' => $line['product']->id,
                'type' => 'in',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'currency' => $currency,
                'user_id' => $user->id,
                'movement_date' => $date,
                'source_type' => Purchase::class,
                'source_id' => $purchase->id,
            ]);

            $line['product']->increment('current_stock', $line['quantity']);
            $purchaseItem->update(['stock_movement_id' => $movement->id]);
        }
    }

    /**
     * Debt leg for the full purchase total (when a supplier is attached) plus
     * the initial payment leg, then stores the resulting ids on the Purchase.
     */
    private function postLedgerLegs(Purchase $purchase, float $total, float $initialPayment, User $user, $date, string $currency): void
    {
        $updates = [];

        if ($purchase->account_id) {
            $account = Account::findOrFail($purchase->account_id);
            $debtTransaction = $this->createDebtTransaction($account, 'purchase', $total, $purchase, $user->id, $date, $currency);
            $updates['debt_account_transaction_id'] = $debtTransaction->id;
        }

        if ($initialPayment > 0) {
            if ($purchase->account_id) {
                [$paymentTransaction, $cashTransaction] = $this->createPaymentLeg($account, 'payment', $initialPayment, $purchase, $user->id, $date, $currency);
                $updates['payment_account_transaction_id'] = $paymentTransaction->id;
                $updates['cash_transaction_id'] = $cashTransaction->id;
            } else {
                $cashTransaction = $this->createCashOnlyLeg('payment', $initialPayment, $purchase, $user->id, $date, $currency);
                $updates['cash_transaction_id'] = $cashTransaction->id;
            }
        }

        if ($updates) {
            $purchase->update($updates);
        }
    }

    /**
     * @return array{0: float, 1: string} [paid_amount, status]
     */
    private function resolvePayment(string $paymentType, float $total, ?float $requestedPaidAmount): array
    {
        return match ($paymentType) {
            'pesin' => [$total, 'paid'],
            'vadeli' => [0.0, 'unpaid'],
            'kismi' => $this->resolvePartialPayment($total, $requestedPaidAmount),
            default => throw new RuntimeException('Geçersiz ödeme tipi.'),
        };
    }

    private function resolvePartialPayment(float $total, ?float $requestedPaidAmount): array
    {
        if ($requestedPaidAmount === null || $requestedPaidAmount <= 0 || $requestedPaidAmount >= $total) {
            throw new RuntimeException('Kısmi ödeme tutarı 0 ile genel toplam arasında olmalıdır.');
        }

        return [$requestedPaidAmount, 'partial'];
    }
}
