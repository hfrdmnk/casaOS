<?php

namespace App\Actions\Finance;

use InvalidArgumentException;

/**
 * Money is stored as signed integers in Rappen (minor units).
 */
final class Money
{
    /**
     * Parse a decimal amount as written in a bank file into Rappen. Returns null for an empty cell.
     *
     * @throws InvalidArgumentException when the value is not an amount in the given notation.
     */
    public static function parse(string $value, string $decimalSeparator, ?string $thousandsSeparator): ?int
    {
        $normalized = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);

        if ($normalized === '') {
            return null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $normalized, $matches) === 1) {
            $negative = true;
            $normalized = $matches[1];
        }

        if (str_ends_with($normalized, '-')) {
            $negative = ! $negative;
            $normalized = substr($normalized, 0, -1);
        }

        if (str_starts_with($normalized, '-')) {
            $negative = ! $negative;
            $normalized = substr($normalized, 1);
        } elseif (str_starts_with($normalized, '+')) {
            $normalized = substr($normalized, 1);
        }

        if ($thousandsSeparator !== null && $thousandsSeparator !== '') {
            $separators = $thousandsSeparator === "'" ? ["'", '’'] : [$thousandsSeparator];
            $normalized = str_replace($separators, '', $normalized);
        }

        $pattern = '/^(\d+)(?:'.preg_quote($decimalSeparator, '/').'(\d+))?$/';

        if (preg_match($pattern, $normalized, $matches) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not an amount with \"{$decimalSeparator}\" as the decimal separator");
        }

        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException("\"{$value}\" has more than two decimal places");
        }

        $minor = (int) $matches[1] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$minor : $minor;
    }

    /**
     * Format Rappen as a plain decimal string, e.g. `-1234.50`.
     */
    public static function format(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * Format Rappen for people, as the web UI does: `CHF 1'234.50`, `CHF -6.70`.
     */
    public static function chf(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return sprintf('CHF %s%s.%02d', $sign, number_format(intdiv($absolute, 100), 0, '', "'"), $absolute % 100);
    }
}
