<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Concerns\ManagesLedgerEntries;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleService
{
    use ManagesLedgerEntries;

    /**
     * Creates a Sale with its items, stock movements, and (depending on
     * account_id/payment_type) the account_transaction/cash_transaction
     * legs — all inside one DB::transaction. Any failure (insufficient
     * stock, bad totals) rolls back every row, including the Sale header
     * itself.
     */
    public function create(array $data, User $user): Sale
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
                    throw new RuntimeException("Bir satışta tüm ürünler aynı para birimine ait olmalıdır: {$documentCurrency} ile {$product->currency} karıştırılamaz.");
                }

                if ($product->current_stock < $item['quantity']) {
                    throw new RuntimeException("Yetersiz stok: {$product->name} (mevcut stok: {$product->current_stock}).");
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

            $saleDate = $data['sale_date'] ?? now();

            $sale = Sale::create([
                'number' => Sale::generateNumber(),
                'account_id' => $data['account_id'] ?? null,
                'payment_type' => $data['payment_type'],
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'currency' => $documentCurrency,
                'status' => $status,
                'note' => $data['note'] ?? null,
                'sale_date' => $saleDate,
                'due_date' => $data['due_date'] ?? null,
                'user_id' => $user->id,
            ]);

            foreach ($lines as $line) {
                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    // Snapshot only — the product's *current* purchase_price at
                    // the moment of sale, frozen forever. Never recomputed via
                    // FIFO/weighted-average, and never touched again even if
                    // the product's purchase_price changes later.
                    'cost_price' => $line['product']->purchase_price,
                    'line_total' => $line['line_total'],
                ]);

                $movement = StockMovement::create([
                    'product_id' => $line['product']->id,
                    'type' => 'out',
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'currency' => $documentCurrency,
                    'user_id' => $user->id,
                    'movement_date' => $saleDate,
                    'source_type' => Sale::class,
                    'source_id' => $sale->id,
                ]);

                $line['product']->decrement('current_stock', $line['quantity']);
                $saleItem->update(['stock_movement_id' => $movement->id]);
            }

            $updates = [];

            if ($sale->account_id) {
                $account = Account::findOrFail($sale->account_id);
                $debtTransaction = $this->createDebtTransaction($account, 'sale', $total, $sale, $user->id, $saleDate, $documentCurrency);
                $updates['debt_account_transaction_id'] = $debtTransaction->id;
            }

            if ($paidAmount > 0) {
                if ($sale->account_id) {
                    [$paymentTransaction, $cashTransaction] = $this->createPaymentLeg($account, 'collection', $paidAmount, $sale, $user->id, $saleDate, $documentCurrency);
                    $updates['payment_account_transaction_id'] = $paymentTransaction->id;
                    $updates['cash_transaction_id'] = $cashTransaction->id;
                } else {
                    $cashTransaction = $this->createCashOnlyLeg('collection', $paidAmount, $sale, $user->id, $saleDate, $documentCurrency);
                    $updates['cash_transaction_id'] = $cashTransaction->id;
                }
            }

            if ($updates) {
                $sale->update($updates);
            }

            return $sale->refresh();
        });
    }

    /**
     * Reverses every child row (stock movements, debt/payment account
     * transactions, cash transaction) atomically, then marks the Sale
     * itself cancelled. Never deletes anything.
     */
    public function cancel(Sale $sale, User $user): void
    {
        if ($sale->isCancelled()) {
            throw new RuntimeException('Bu satış zaten iptal edilmiş.');
        }

        DB::transaction(function () use ($sale, $user) {
            $reason = 'Satış iptali: '.$sale->number;

            foreach ($sale->items as $item) {
                $item->stockMovement?->cancel($user, $reason);
            }

            $sale->debtAccountTransaction?->cancel($user, $reason);
            $sale->paymentAccountTransaction?->cancel($user, $reason);
            $sale->cashTransaction?->cancel($user, $reason);

            $sale->forceFill(['cancelled_at' => now()])->save();
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
