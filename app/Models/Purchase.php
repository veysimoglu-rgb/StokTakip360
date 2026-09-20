<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
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
        'total', 'paid_amount', 'currency', 'status', 'note', 'purchase_date', 'due_date', 'user_id',
        'debt_account_transaction_id', 'payment_account_transaction_id',
        'cash_transaction_id', 'cancelled_at', 'edited_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'purchase_date' => 'datetime',
        'due_date' => 'date',
        'cancelled_at' => 'datetime',
        'edited_at' => 'datetime',
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
        return $this->hasMany(PurchaseItem::class);
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
     * Opaque fingerprint of everything an edit would overwrite. The edit form
     * carries it; PurchaseService::update() refuses to save when it no longer
     * matches (someone edited, paid or otherwise changed the purchase in the
     * meantime). It combines updated_at with the ids of the current ledger
     * legs, which are re-created by every edit, so two edits inside the same
     * second still differ.
     */
    public function versionToken(): string
    {
        return sha1(implode('|', [
            $this->id,
            $this->updated_at?->format('Y-m-d H:i:s'),
            $this->total,
            $this->paid_amount,
            $this->status,
            $this->cancelled_at?->format('Y-m-d H:i:s'),
            $this->debt_account_transaction_id,
            $this->payment_account_transaction_id,
            $this->cash_transaction_id,
        ]));
    }

    /**
     * Unpaid (or partially paid), not cancelled, and past its due date.
     * A fully paid or cancelled purchase is never overdue, no matter how
     * old its due_date is.
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

    public static function generateNumber(): string
    {
        $next = static::count() + 1;

        do {
            $number = 'ALS-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (static::where('number', $number)->exists());

        return $number;
    }
}
