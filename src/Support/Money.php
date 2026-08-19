<?php

namespace Square1\Mpp\Support;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Convert between decimal amount strings (e.g. "0.50") and Stripe minor units
 * (integer cents). String and BCMath maths, no float drift, and it fails closed
 * rather than saturating an out-of-range amount.
 */
class Money
{
    /**
     * @var list<string> Currencies Stripe charges in whole (zero-decimal) units,
     *                   where the minor-unit amount equals the whole amount.
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * @var list<string> Currencies Stripe treats as zero-decimal but requires to
     *                   be sent as a two-decimal value ending in `00` (whole units only). See
     *                   https://docs.stripe.com/currencies special cases.
     */
    private const WHOLE_UNIT = ['ISK', 'UGX'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Is this a well-formed decimal amount string ("0.50", "5", "-1.25")?
     *
     * The single definition of the format for the package, so a price resolved at
     * the gate is judged by the same rule that converts it at mint time — a
     * scientific-notation or signed-plus value is rejected where it is set, not
     * later. Says nothing about sign or magnitude; callers add their own rules.
     */
    public static function isValidAmount(string $amount): bool
    {
        $amount = trim($amount);

        return $amount !== '' && $amount !== '-' && (bool) preg_match('/^-?\d*(\.\d+)?$/', $amount);
    }

    /**
     * "0.50" USD -> 50 ; "1.00" USD -> 100 ; "5" JPY -> 5.
     */
    public static function toMinorUnits(string $amount, string $currency): int
    {
        $amount = trim($amount);

        if (! self::isValidAmount($amount)) {
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

        // Digit string of the value in minor units, with leading zeros trimmed
        // for a clean numeric comparison.
        $minorDigits = ltrim($whole.$frac, '0');
        $minorDigits = $minorDigits === '' ? '0' : $minorDigits;

        // Fail closed above PHP_INT_MAX instead of saturating the (int) cast.
        if (bccomp($minorDigits, (string) PHP_INT_MAX) > 0) {
            throw new InvalidConfigurationException(
                "Money amount '{$amount}' exceeds the maximum supported minor-unit value for {$currency}."
            );
        }

        $minor = (int) $minorDigits;

        // ISK and UGX must be whole units (minor units divisible by 100). Reject
        // a fractional amount rather than charging a value Stripe forbids.
        if (in_array(strtoupper($currency), self::WHOLE_UNIT, true) && $minor % 100 !== 0) {
            throw new InvalidConfigurationException(
                "Money amount '{$amount}' must be a whole {$currency}; Stripe does not allow fractional {$currency}."
            );
        }

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
