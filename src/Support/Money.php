<?php

namespace Square1\Mpp\Support;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Converts between a decimal amount string, such as "0.50", and the minor units
 * of Stripe, which are integer cents.
 *
 * The class uses string arithmetic and BCMath, so a float never changes a value.
 * It fails closed on an amount that is out of range, and does not clamp it.
 */
class Money
{
    /**
     * @var list<string> The currencies that Stripe charges in whole units, which
     *                   are zero-decimal currencies. For these, the minor-unit amount equals
     *                   the whole amount.
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * @var list<string> The currencies that Stripe treats as zero-decimal, but
     *                   requires as a two-decimal value that ends in `00`. Only whole units are
     *                   valid. See the special cases at https://docs.stripe.com/currencies.
     */
    private const WHOLE_UNIT = ['ISK', 'UGX'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Reports whether a string is a well-formed decimal amount, such as "0.50",
     * "5" or "-1.25".
     *
     * This is the one definition of the format for the package. A price that the
     * gate resolves therefore passes the same rule that converts it at mint time.
     * The method rejects scientific notation and a leading plus sign where a site
     * owner sets them, and not later.
     *
     * The method states nothing about the sign or the size of the amount. A
     * caller adds its own rules.
     */
    public static function isValidAmount(string $amount): bool
    {
        $amount = trim($amount);

        return $amount !== '' && $amount !== '-' && (bool) preg_match('/^-?\d*(\.\d+)?$/', $amount);
    }

    /**
     * Converts a decimal amount to minor units.
     *
     * "0.50" USD becomes 50. "1.00" USD becomes 100. "5" JPY becomes 5.
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

        // This is the value in minor units, as a digit string. The method
        // removes the leading zeros, so that the comparison below is a clean
        // numeric one.
        $minorDigits = ltrim($whole.$frac, '0');
        $minorDigits = $minorDigits === '' ? '0' : $minorDigits;

        // Fail closed above PHP_INT_MAX. Do not clamp the value with an (int)
        // cast.
        if (bccomp($minorDigits, (string) PHP_INT_MAX) > 0) {
            throw new InvalidConfigurationException(
                "Money amount '{$amount}' exceeds the maximum supported minor-unit value for {$currency}."
            );
        }

        $minor = (int) $minorDigits;

        // ISK and UGX must be whole units, so the minor units must divide by
        // 100. Reject a fractional amount, and do not charge a value that Stripe
        // forbids.
        if (in_array(strtoupper($currency), self::WHOLE_UNIT, true) && $minor % 100 !== 0) {
            throw new InvalidConfigurationException(
                "Money amount '{$amount}' must be a whole {$currency}. Stripe does not allow a fractional {$currency}."
            );
        }

        return $negative ? -$minor : $minor;
    }

    /**
     * Converts minor units to a decimal amount.
     *
     * 50 USD becomes "0.50". 100 USD becomes "1.00". 5 JPY becomes "5".
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
