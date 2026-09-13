<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AccountTransaction extends Model
{
    public const TYPES = [
        'sale' => 'Satış',
        'purchase' => 'Alış',
        'collection' => 'Tahsilat',
        'payment' => 'Ödeme',
        'manual_debt' => 'Manuel Borç',
        'manual_credit' => 'Manuel Alacak',
    ];

    /**
     * Direction is derived from type, never chosen independently, so a
     * transaction can't end up debit-labeled "Tahsilat" or similar.
     */
    public const TYPE_DIRECTIONS = [
        'sale' => 'debit',
        'purchase' => 'debit',
        'collection' => 'credit',
        'payment' => 'credit',
        'manual_debt' => 'debit',
        'manual_credit' => 'credit',
    ];

    protected $fillable = [
        'account_id', 'type', 'direction', 'amount', 'currency', 'description',
        'transaction_date', 'user_id', 'reversal_of_id', 'cancelled_at',
        'source_type', 'source_id', 'applies_to_type', 'applies_to_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
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

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function source()
    {
        return $this->morphTo();
    }

    /**
     * Which open Sale/Purchase (if any) this manual collection/payment was
     * applied against — independent of `source`, which for these rows
     * already means "the paired cash_transaction" (see WP-10e).
     */
    public function appliesTo()
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Reverses this transaction with an opposite-direction entry instead of
     * deleting it, so the ledger stays fully auditable. Guards against
     * cancelling twice or cancelling a reversal itself.
     */
    public function cancel(?User $user = null, ?string $reason = null): self
    {
        if ($this->isCancelled()) {
            throw new RuntimeException('Bu hareket zaten iptal edilmiş.');
        }

        if ($this->reversal_of_id !== null) {
            throw new RuntimeException('İptal kayıtları tekrar iptal edilemez.');
        }

        $reversal = static::create([
            'account_id' => $this->account_id,
            'type' => $this->type,
            'direction' => $this->direction === 'debit' ? 'credit' : 'debit',
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $reason ?? 'İptal: '.($this->description ?? $this->typeLabel()),
            'transaction_date' => now(),
            'user_id' => $user?->id,
            'reversal_of_id' => $this->id,
        ]);

        $this->forceFill(['cancelled_at' => now()])->save();

        return $reversal;
    }
}
