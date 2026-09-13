<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Account extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = [
        'customer' => 'Müşteri',
        'supplier' => 'Tedarikçi',
        'other' => 'Diğer',
    ];

    protected $fillable = [
        'code', 'type', 'name', 'contact_person', 'phone', 'email',
        'address', 'tax_office', 'tax_no', 'note', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function transactions()
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isCustomer(): bool
    {
        return $this->type === 'customer';
    }

    public function isSupplier(): bool
    {
        return $this->type === 'supplier';
    }

    public function cashTransactions()
    {
        return $this->hasMany(CashTransaction::class);
    }

    /**
     * With no $currency, sums debit across every currency this account has
     * ever moved in — the original, pre-multi-currency behavior, kept as
     * the default so nothing that already calls totalDebt()/totalCredit()
     * without an argument changes.
     */
    public function totalDebt(?string $currency = null): float
    {
        return (float) $this->transactions()
            ->where('direction', 'debit')
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->sum('amount');
    }

    public function totalCredit(?string $currency = null): float
    {
        return (float) $this->transactions()
            ->where('direction', 'credit')
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->sum('amount');
    }

    /**
     * Net balance for one specific currency — the currency-aware
     * replacement for balance(). Every account transaction this system has
     * ever created is 'TL' (multi-currency sale/purchase/collection flows
     * ship in WP-8b/8c), so for every account that exists today this
     * returns exactly what balance() already returns for 'TL'.
     */
    public function balanceForCurrency(string $currency): float
    {
        if (! in_array($currency, Currency::LIST, true)) {
            throw new InvalidArgumentException("Geçersiz para birimi: {$currency}");
        }

        return $this->totalDebt($currency) - $this->totalCredit($currency);
    }

    /**
     * Every currency this account has any activity in, mapped to its net
     * balance — one aggregate query, not a loop over Currency::LIST.
     * Currencies with zero activity are simply absent from the result.
     */
    public function balancesByCurrency(): Collection
    {
        return $this->transactions()
            ->selectRaw("currency, SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as balance")
            ->groupBy('currency')
            ->pluck('balance', 'currency')
            ->map(fn ($value) => (float) $value);
    }

    /**
     * Backward-compatible: this is what every existing caller (views,
     * tests, WP-2 through WP-6) already means by "the account's balance" —
     * kept as an alias for balanceForCurrency('TL') so the signature and,
     * for every TL-only account, the exact return value never change.
     */
    public function balance(): float
    {
        return $this->balanceForCurrency('TL');
    }

    public static function generateCode(): string
    {
        $next = static::withTrashed()->count() + 1;

        do {
            $code = 'CH-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (static::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
