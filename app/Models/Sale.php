<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    public const PAYMENT_TYPES = [
        'pesin' => 'Peşin',
        'vadeli' => 'Vadeli',
        'kismi' => 'Kısmi Ödeme',
    ];

    public const STATUSES = [
        'unpaid' => 'Vadeli',
        'partial' => 'Kısmi Ödendi',
        'paid' => 'Ödendi',
    ];

    protected $fillable = [
        'number', 'account_id', 'payment_type', 'subtotal', 'discount_total',
        'total', 'paid_amount', 'currency', 'status', 'note', 'sale_date', 'due_date', 'user_id',
        'debt_account_transaction_id', 'payment_account_transaction_id',
        'cash_transaction_id', 'cancelled_at', 'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'sale_date' => 'datetime',
        'due_date' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function debtAccountTransaction()
    {
        return $this->belongsTo(AccountTransaction::class, 'debt_account_transaction_id');
    }

    public function paymentAccountTransaction()
    {
        return $this->belongsTo(AccountTransaction::class, 'payment_account_transaction_id');
    }

    public function cashTransaction()
    {
        return $this->belongsTo(CashTransaction::class);
    }

    public function paymentTypeLabel(): string
    {
        return self::PAYMENT_TYPES[$this->payment_type] ?? $this->payment_type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function remaining(): float
    {
        return (float) $this->total - (float) $this->paid_amount;
    }

    /**
     * The cari's balance (in this order's currency) as it stood right before
     * this order was placed: every ledger row of the account created before
     * the order's own first cari row. Cancel/reversal pairs, collections and
     * other currencies therefore behave exactly as in the account statement
     * (a pair nets to zero; USD never leaks into a TL order), and neither the
     * order's own debt/payment legs nor later collections or edits can shift
     * it. Null when the order has no cari (walk-in cash order).
     */
    public function carriedBalance(): ?float
    {
        if (! $this->account_id) {
            return null;
        }

        $firstOwnId = AccountTransaction::where('source_type', self::class)
            ->where('source_id', $this->id)
            ->where('account_id', $this->account_id)
            ->where('currency', $this->currency)
            ->min('id');

        return (float) (AccountTransaction::where('account_id', $this->account_id)
            ->where('currency', $this->currency)
            ->when($firstOwnId !== null, fn ($q) => $q->where('id', '<', $firstOwnId))
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as balance")
            ->value('balance') ?? 0);
    }

    public function totalWeightKg(): float
    {
        return (float) $this->items->sum(fn ($item) => (float) $item->line_weight_kg);
    }

    /**
     * Unpaid (or partially paid), not cancelled, and past its due date.
     * A fully paid or cancelled sale is never overdue, no matter how old
     * its due_date is.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->whereColumn('paid_amount', '<', 'total')
            ->whereNull('cancelled_at');
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->lt(today())
            && $this->remaining() > 0
            && ! $this->isCancelled();
    }

    /**
     * New orders are numbered SIP-000020. The sequence simply continues after
     * the existing rows (legacy SAT-… numbers are never touched or renumbered).
     */
    public static function generateNumber(): string
    {
        $next = static::count() + 1;

        do {
            $number = 'SIP-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (static::where('number', $number)->exists());

        return $number;
    }
}
