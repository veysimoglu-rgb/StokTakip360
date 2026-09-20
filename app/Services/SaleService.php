<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Concerns\ManagesLedgerEntries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleService
{
    use ManagesLedgerEntries;

    /**
     * Creates a Sale with its items, stock movements, and (depending on
     * account_id/payment_type) the account_transaction/cash_transaction
     * legs — all inside one DB::transaction. Any failure (unconfirmed stock
     * shortage, bad totals) rolls back every row, including the Sale header
     * itself.
     */
    public function create(array $data, User $user): Sale
    {
        return DB::transaction(function () use ($data, $user) {
            $products = $this->lockProducts($data['items']);

            $order = $this->prepareOrder($data, $products);
            $this->assertShortagesConfirmed($order['shortages'], $data);

            [$paidAmount, $status] = $this->resolvePayment($data['payment_type'], $order['total'], $data['paid_amount'] ?? null);
            $saleDate = $data['sale_date'] ?? now();

            $sale = Sale::create([
                'number' => Sale::generateNumber(),
                'account_id' => $data['account_id'] ?? null,
                'payment_type' => $data['payment_type'],
                'subtotal' => $order['subtotal'],
                'discount_total' => $order['discount'],
                'total' => $order['total'],
                'paid_amount' => $paidAmount,
                'currency' => $order['currency'],
                'status' => $status,
                'note' => $data['note'] ?? null,
                'sale_date' => $saleDate,
                'due_date' => $data['due_date'] ?? null,
                'user_id' => $user->id,
            ]);

            $this->applyLines($sale, $order['lines'], $user, $saleDate, $order['currency']);
            $this->postLedgerLegs($sale, $order['total'], $paidAmount, $user, $saleDate, $order['currency']);

            return $sale->refresh();
        });
    }

    /**
     * Re-opens a saved order using the app's immutable-ledger pattern:
     * every stock/debt/payment/cash row the order produced is reversed via
     * its own cancel() (nothing is deleted or edited in place), the order's
     * item rows are replaced, and fresh rows are created for the new
     * content. The Sale header (id, number, date) stays the same, so users
     * see one order — the ledger nets to exactly the difference.
     */
    public function update(Sale $sale, array $data, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);

            if ($sale->isCancelled()) {
                throw new RuntimeException('İptal edilmiş bir sipariş düzenlenemez.');
            }

            $sale->load('items');

            // Money collected later through the cari screen (applied to this
            // order) is not part of the order's own payment leg — it is kept.
            // Those collections were booked on this order's cari and currency,
            // so neither may change while they exist.
            $laterCollections = (float) AccountTransaction::where('applies_to_type', Sale::class)
                ->where('applies_to_id', $sale->id)
                ->where('type', 'collection')
                ->whereNull('cancelled_at')
                ->whereNull('reversal_of_id')
                ->sum('amount');

            if ($laterCollections > 0 && (int) ($data['account_id'] ?? 0) !== (int) $sale->account_id) {
                throw new RuntimeException('Bu siparişe sonradan yapılan tahsilatlar var; müşteri (cari) değiştirilemez. Önce ilgili tahsilatları iptal edin.');
            }

            // Lock every product involved (old + new lines) in id order
            // before any stock is touched, so concurrent orders can't
            // deadlock or read stale stock.
            $lockItems = collect($sale->items->map(fn ($i) => ['product_id' => $i->product_id]))
                ->merge($data['items'])
                ->all();
            $this->lockProducts($lockItems);

            $reason = 'Sipariş düzenlendi: '.$sale->number;

            foreach ($sale->items as $oldItem) {
                $oldItem->stockMovement?->cancel($user, $reason);
            }

            $sale->debtAccountTransaction?->cancel($user, $reason);
            $sale->paymentAccountTransaction?->cancel($user, $reason);
            $sale->cashTransaction?->cancel($user, $reason);

            $sale->items()->delete();

            // Re-read stock AFTER the reversals so availability reflects the
            // restored quantities.
            $products = $this->lockProducts($data['items']);

            $order = $this->prepareOrder($data, $products);

            if ($laterCollections > 0 && $order['currency'] !== $sale->currency) {
                throw new RuntimeException('Bu siparişe sonradan yapılan tahsilatlar var; para birimi değiştirilemez. Önce ilgili tahsilatları iptal edin.');
            }

            $this->assertShortagesConfirmed($order['shortages'], $data);

            [$initialPayment] = $this->resolvePayment($data['payment_type'], $order['total'], $data['paid_amount'] ?? null);

            $paidAmount = round($initialPayment + $laterCollections, 2);

            if ($paidAmount > $order['total'] + 0.00001) {
                throw new RuntimeException('Bu siparişe sonradan yapılan tahsilatlar ('.number_format($laterCollections, 2, ',', '.').') yeni sipariş toplamından fazla olacak. Önce ilgili tahsilatı iptal edin.');
            }

            $status = match (true) {
                $paidAmount >= $order['total'] - 0.00001 => 'paid',
                $paidAmount > 0 => 'partial',
                default => 'unpaid',
            };

            $sale->forceFill([
                'account_id' => $data['account_id'] ?? null,
                'payment_type' => $data['payment_type'],
                'subtotal' => $order['subtotal'],
                'discount_total' => $order['discount'],
                'total' => $order['total'],
                'paid_amount' => $paidAmount,
                'currency' => $order['currency'],
                'status' => $status,
                'note' => $data['note'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'edited_at' => now(),
                'debt_account_transaction_id' => null,
                'payment_account_transaction_id' => null,
                'cash_transaction_id' => null,
            ])->save();

            $this->applyLines($sale, $order['lines'], $user, $sale->sale_date, $order['currency']);
            $this->postLedgerLegs($sale, $order['total'], $initialPayment, $user, $sale->sale_date, $order['currency']);

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
            throw new RuntimeException('Bu sipariş zaten iptal edilmiş.');
        }

        DB::transaction(function () use ($sale, $user) {
            $reason = 'Sipariş iptali: '.$sale->number;

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
     * Validates and prices every line against the (already locked) products.
     * Stock is never allowed to go below zero: each line only "fulfils" what
     * is actually left in stock (tracked per product across lines), and the
     * rest is recorded as that line's stock_shortfall_quantity. The order
     * itself (quantity, line_total, cari/kasa) always reflects what the
     * customer ordered.
     *
     * @return array{lines: array, subtotal: float, discount: float, total: float, currency: string, shortages: array}
     */
    private function prepareOrder(array $data, Collection $products): array
    {
        $subtotal = 0.0;
        $lines = [];
        $shortages = [];
        $currency = null;
        $remainingStock = [];

        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw new RuntimeException('Seçilen ürün bulunamadı.');
            }

            if ($currency === null) {
                $currency = $product->currency;
            } elseif ($product->currency !== $currency) {
                throw new RuntimeException("Bir siparişte tüm ürünler aynı para birimine ait olmalıdır: {$currency} ile {$product->currency} karıştırılamaz.");
            }

            $packageCount = null;
            $multiplier = null;
            $lineWeight = null;
            $quantity = round((float) $item['quantity'], 3);

            // Packaged products: the backend, not the browser, is the
            // authority on balya -> adet.
            if ($product->hasPackaging() && ! empty($item['package_qty_input']) && (float) $item['package_qty_input'] > 0) {
                $packageCount = round((float) $item['package_qty_input'], 3);
                $multiplier = $product->baseUnitsPerPackage();
                $quantity = round($packageCount * $multiplier, 3);

                if ($product->package_weight_kg !== null) {
                    $lineWeight = round($packageCount * (float) $product->package_weight_kg, 3);
                }
            }

            if ($quantity <= 0) {
                throw new RuntimeException("Geçersiz miktar: {$product->name}.");
            }

            $remainingStock[$product->id] ??= max(0.0, (float) $product->current_stock);
            $fulfilled = round(min($quantity, $remainingStock[$product->id]), 3);
            $remainingStock[$product->id] = round($remainingStock[$product->id] - $fulfilled, 3);
            $shortfall = round($quantity - $fulfilled, 3);

            if ($shortfall > 0) {
                $shortages[] = [
                    'product' => $product->name,
                    'requested' => $quantity,
                    'available' => $fulfilled,
                    'shortfall' => $shortfall,
                ];
            }

            $unitPrice = (float) $item['unit_price'];
            $lineTotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineTotal;

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'fulfilled' => $fulfilled,
                'shortfall' => $shortfall,
                'package_qty_input' => $packageCount,
                'unit_multiplier_snapshot' => $multiplier,
                'line_weight_kg' => $lineWeight,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = round((float) ($data['discount_total'] ?? 0), 2);

        if ($discount > $subtotal) {
            throw new RuntimeException('İskonto tutarı ara toplamdan büyük olamaz.');
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => round($subtotal - $discount, 2),
            'currency' => $currency,
            'shortages' => $shortages,
        ];
    }

    private function assertShortagesConfirmed(array $shortages, array $data): void
    {
        if ($shortages === [] || ! empty($data['confirm_insufficient_stock'])) {
            return;
        }

        $parts = array_map(
            fn ($s) => sprintf('%s (istenen: %s, stokta: %s)', $s['product'], $this->plain($s['requested']), $this->plain($s['available'])),
            $shortages
        );

        throw new InsufficientStockException('Yetersiz stok: '.implode('; ', $parts).'.', $shortages);
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    /**
     * Creates the SaleItem rows plus, for whatever part of each line is
     * actually in stock, the stock 'out' movement and stock decrement.
     * Nothing is ever taken beyond available stock, so current_stock cannot
     * go negative.
     */
    private function applyLines(Sale $sale, array $lines, User $user, $date, string $currency): void
    {
        foreach ($lines as $line) {
            $saleItem = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'stock_shortfall_quantity' => $line['shortfall'],
                'package_qty_input' => $line['package_qty_input'],
                'unit_multiplier_snapshot' => $line['unit_multiplier_snapshot'],
                'line_weight_kg' => $line['line_weight_kg'],
                'unit_price' => $line['unit_price'],
                // Snapshot only — the product's *current* purchase_price at
                // the moment of sale, frozen forever. Never recomputed via
                // FIFO/weighted-average, and never touched again even if
                // the product's purchase_price changes later.
                'cost_price' => $line['product']->purchase_price,
                'line_total' => $line['line_total'],
            ]);

            if ($line['fulfilled'] <= 0) {
                continue;
            }

            $movement = StockMovement::create([
                'product_id' => $line['product']->id,
                'type' => 'out',
                'quantity' => $line['fulfilled'],
                'unit_price' => $line['unit_price'],
                'currency' => $currency,
                'user_id' => $user->id,
                'movement_date' => $date,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
            ]);

            $line['product']->decrement('current_stock', $line['fulfilled']);
            $saleItem->update(['stock_movement_id' => $movement->id]);
        }
    }

    /**
     * Debt leg for the full order total (when a cari is attached) plus the
     * initial payment leg, then stores the resulting ids on the Sale.
     */
    private function postLedgerLegs(Sale $sale, float $total, float $initialPayment, User $user, $date, string $currency): void
    {
        $updates = [];

        if ($sale->account_id) {
            $account = Account::findOrFail($sale->account_id);
            $debtTransaction = $this->createDebtTransaction($account, 'sale', $total, $sale, $user->id, $date, $currency);
            $updates['debt_account_transaction_id'] = $debtTransaction->id;
        }

        if ($initialPayment > 0) {
            if ($sale->account_id) {
                [$paymentTransaction, $cashTransaction] = $this->createPaymentLeg($account, 'collection', $initialPayment, $sale, $user->id, $date, $currency);
                $updates['payment_account_transaction_id'] = $paymentTransaction->id;
                $updates['cash_transaction_id'] = $cashTransaction->id;
            } else {
                $cashTransaction = $this->createCashOnlyLeg('collection', $initialPayment, $sale, $user->id, $date, $currency);
                $updates['cash_transaction_id'] = $cashTransaction->id;
            }
        }

        if ($updates) {
            $sale->update($updates);
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
