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

    public static function format(float $amount, ?string $currency): string
    {
        $number = number_format($amount, 2, ',', '.');

        if ($currency === null) {
            return $number;
        }

        return $number.' '.$currency;
    }

    public static function formatWithSymbol(float $amount, ?string $currency): string
    {
        $number = number_format($amount, 2, ',', '.');

        if ($currency === null) {
            return $number;
        }

        $symbol = self::SYMBOLS[$currency] ?? $currency;

        return $symbol.' '.$number;
    }
}
