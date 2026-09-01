<?php

namespace App\Support;

class Currency
{
    public const LIST = ['TL', 'USD', 'EUR'];

    public const SYMBOLS = [
        'TL' => '₺',
        'USD' => '$',
        'EUR' => '€',
    ];

    public static function format(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.').' '.$currency;
    }

    public static function formatWithSymbol(float $amount, string $currency): string
    {
        $symbol = self::SYMBOLS[$currency] ?? $currency;

        return $symbol.' '.number_format($amount, 2, ',', '.');
    }
}
