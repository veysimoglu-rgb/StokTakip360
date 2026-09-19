<?php

namespace App\Support;

class Quantity
{
    /**
     * Turkish display format for stock/order quantities: thousands with ".",
     * decimals with "," and no trailing zeros (15000 -> "15.000", 0.345 ->
     * "0,345", "17.500" -> "17,5"). Display only — never used to build a
     * value that is sent back to the backend.
     */
    public static function format(float|int|string|null $value, int $maxDecimals = 3): string
    {
        $formatted = number_format((float) $value, $maxDecimals, ',', '.');

        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }
}
