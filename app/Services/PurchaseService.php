<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Concerns\ManagesLedgerEntries;
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

            $total = $subtotal - $discountTotal;
            [$paidAmount, $status] = $this->resolvePayment($data['payment_type'], $total, $data['paid_amount'] ?? null);

            $purchaseDate = $data['purchase_date'] ?? now();

            $purchase = Purchase::create([
                'number' => Purchase::generateNumber(),
                'account_id' => $data['account_id'] ?? null,
                'payment_type' => $data['payment_type'],
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'currency' => $documentCurrency,
                'status' => $status,
                'note' => $data['note'] ?? null,
                'purchase_date' => $purchaseDate,
                'due_date' => $data['due_date'] ?? null,
                'user_id' => $user->id,
            ]);

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
                    'currency' => $documentCurrency,
                    'user_id' => $user->id,
                    'movement_date' => $purchaseDate,
                    'source_type' => Purchase::class,
                    'source_id' => $purchase->id,
                ]);

                $line['product']->increment('current_stock', $line['quantity']);
                $purchaseItem->update(['stock_movement_id' => $movement->id]);
            }

            $updates = [];

            if ($purchase->account_id) {
                $account = Account::findOrFail($purchase->account_id);
                $debtTransaction = $this->createDebtTransaction($account, 'purchase', $total, $purchase, $user->id, $purchaseDate, $documentCurrency);
                $updates['debt_account_transaction_id'] = $debtTransaction->id;
            }

            if ($paidAmount > 0) {
                if ($purchase->account_id) {
                    [$paymentTransaction, $cashTransaction] = $this->createPaymentLeg($account, 'payment', $paidAmount, $purchase, $user->id, $purchaseDate, $documentCurrency);
                    $updates['payment_account_transaction_id'] = $paymentTransaction->id;
                    $updates['cash_transaction_id'] = $cashTransaction->id;
                } else {
                    $cashTransaction = $this->createCashOnlyLeg('payment', $paidAmount, $purchase, $user->id, $purchaseDate, $documentCurrency);
                    $updates['cash_transaction_id'] = $cashTransaction->id;
                }
            }

            if ($updates) {
                $purchase->update($updates);
            }

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
