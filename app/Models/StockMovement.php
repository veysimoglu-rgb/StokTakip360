<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'type', 'quantity', 'unit_price', 'currency',
        'account_id', 'user_id', 'note', 'movement_date',
        'source_type', 'source_id', 'reversal_of_id', 'cancelled_at',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
        'unit_price' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

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

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * quantity × recorded unit_price, or null when no price was recorded.
     * Never falls back to the product's current price.
     */
    public function amount(): ?float
    {
        if ($this->unit_price <= 0) {
            return null;
        }

        return $this->quantity * (float) $this->unit_price;
    }

    /**
     * Reverses this movement with an opposite-type entry instead of deleting
     * it, mirroring AccountTransaction/CashTransaction::cancel(). Also
     * restores the product's current_stock under a row lock. Reversing an
     * 'in' movement (a purchase) is refused if the stock it added has
     * already been consumed — V1 has no lot tracking, so this is an
     * aggregate-level guard, not per-batch traceability.
     */
    public function cancel(?User $user = null, ?string $reason = null): self
    {
        if ($this->isCancelled()) {
            throw new RuntimeException('Bu stok hareketi zaten iptal edilmiş.');
        }

        if ($this->reversal_of_id !== null) {
            throw new RuntimeException('İptal kayıtları tekrar iptal edilemez.');
        }

        $product = Product::lockForUpdate()->findOrFail($this->product_id);
        $reversedType = $this->type === 'out' ? 'in' : 'out';

        if ($reversedType === 'out' && $product->current_stock < $this->quantity) {
            throw new RuntimeException("Bu harekete ait stok tükenmiş, iptal edilemez. Mevcut stok: {$product->current_stock}");
        }

        $reversal = static::create([
            'product_id' => $this->product_id,
            'type' => $reversedType,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'account_id' => $this->account_id,
            'user_id' => $user?->id,
            'note' => $reason ?? 'İptal: '.($this->note ?? ($this->type === 'out' ? 'Satış' : 'Alış')),
            'movement_date' => now(),
            'reversal_of_id' => $this->id,
        ]);

        if ($reversedType === 'in') {
            $product->increment('current_stock', $this->quantity);
        } else {
            $product->decrement('current_stock', $this->quantity);
        }

        $this->forceFill(['cancelled_at' => now()])->save();

        return $reversal;
    }
}
