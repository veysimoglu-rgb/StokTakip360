<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class CashTransaction extends Model
{
    use HasFactory;

    public const TYPES = [
        'collection' => 'Tahsilat',
        'payment' => 'Ödeme',
        'other_income' => 'Diğer Gelir',
        'expense' => 'Gider',
        'manual_in' => 'Manuel Giriş',
        'manual_out' => 'Manuel Çıkış',
    ];

    /**
     * Direction is derived from type, never chosen independently.
     */
    public const TYPE_DIRECTIONS = [
        'collection' => 'in',
        'other_income' => 'in',
        'manual_in' => 'in',
        'payment' => 'out',
        'expense' => 'out',
        'manual_out' => 'out',
    ];

    protected $fillable = [
        'type', 'direction', 'amount', 'currency', 'description', 'transaction_date',
        'account_id', 'user_id', 'reversal_of_id', 'cancelled_at',
        'source_type', 'source_id',
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

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * With no $currency, sums every currency together — the original,
     * pre-multi-currency behavior, kept as the default so nothing that
     * already calls totalIn()/totalOut() without an argument changes.
     */
    public static function totalIn(?string $currency = null): float
    {
        return (float) static::where('direction', 'in')
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->sum('amount');
    }

    public static function totalOut(?string $currency = null): float
    {
        return (float) static::where('direction', 'out')
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->sum('amount');
    }

    /**
     * Net kasa balance for one specific currency — the currency-aware
     * replacement for balance(). Every cash transaction this system has
     * ever created is 'TL' (multi-currency collect/pay flows ship in
     * WP-8b/8c), so for the till as it exists today this returns exactly
     * what balance() already returns for 'TL'.
     */
    public static function balanceForCurrency(string $currency): float
    {
        if (! in_array($currency, Currency::LIST, true)) {
            throw new InvalidArgumentException("Geçersiz para birimi: {$currency}");
        }

        return static::totalIn($currency) - static::totalOut($currency);
    }

    /**
     * Every currency the till has any activity in, mapped to its net
     * balance — one aggregate query, not a loop over Currency::LIST.
     */
    public static function balancesByCurrency(): Collection
    {
        return static::query()
            ->selectRaw("currency, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as balance")
            ->groupBy('currency')
            ->pluck('balance', 'currency')
            ->map(fn ($value) => (float) $value);
    }

    /**
     * Backward-compatible: this is what every existing caller (the /cash
     * screen, the dashboard, WP-3 through WP-6) already means by "the kasa
     * balance" — kept as an alias for balanceForCurrency('TL') so the
     * signature and, for the TL-only till as it exists today, the exact
     * return value never change.
     */
    public static function balance(): float
    {
        return static::balanceForCurrency('TL');
    }

    /**
     * Reverses this transaction with an opposite-direction entry instead of
     * deleting it, mirroring AccountTransaction::cancel(). Guards against
     * cancelling twice or cancelling a reversal itself.
     */
    public function cancel(?User $user = null, ?string $reason = null): self
    {
        if ($this->isCancelled()) {
            throw new RuntimeException('Bu kasa hareketi zaten iptal edilmiş.');
        }

        if ($this->reversal_of_id !== null) {
            throw new RuntimeException('İptal kayıtları tekrar iptal edilemez.');
        }

        $reversal = static::create([
            'type' => $this->type,
            'direction' => $this->direction === 'in' ? 'out' : 'in',
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $reason ?? 'İptal: '.($this->description ?? $this->typeLabel()),
            'transaction_date' => now(),
            'account_id' => $this->account_id,
            'user_id' => $user?->id,
            'reversal_of_id' => $this->id,
        ]);

        $this->forceFill(['cancelled_at' => now()])->save();

        return $reversal;
    }
}
