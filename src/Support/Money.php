<?php

namespace Square1\Mpp\Support;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Convert between decimal amount strings (e.g. "0.50") and Stripe minor units
 * (integer cents). Pure string maths — no bcmath dependency, no float drift.
 */
class Money
{
    /** @var list<string> Currencies Stripe charges in whole (zero-decimal) units. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * "0.50" USD -> 50 ; "1.00" USD -> 100 ; "5" JPY -> 5.
     */
    public static function toMinorUnits(string $amount, string $currency): int
    {
        $amount = trim($amount);

        if ($amount === '' || $amount === '-' || ! preg_match('/^-?\d*(\.\d+)?$/', $amount)) {
            throw new InvalidConfigurationException("Invalid money amount: '{$amount}'.");
        }

        $decimals = self::decimals($currency);
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');

        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($frac) > $decimals) {
            throw new InvalidConfigurationException(
                "Money amount '{$amount}' has more than {$decimals} decimal places for {$currency}."
            );
        }

        $frac = substr(str_pad($frac, $decimals, '0'), 0, $decimals);

        $minor = (int) ($whole.$frac);

        return $negative ? -$minor : $minor;
    }

    /**
     * 50 USD -> "0.50" ; 100 USD -> "1.00" ; 5 JPY -> "5".
     */
    public static function fromMinorUnits(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);

        if ($decimals === 0) {
            return (string) $minor;
        }

        $negative = $minor < 0;
        $digits = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$decimals);
        $frac = substr($digits, -$decimals);

        return ($negative ? '-' : '').$whole.'.'.$frac;
    }
}
