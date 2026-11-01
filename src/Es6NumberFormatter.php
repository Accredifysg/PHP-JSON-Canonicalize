<?php

namespace Accredify\JsonCanonicalize;

use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;

/**
 * ECMAScript `Number::toString` for IEEE-754 double-precision values.
 *
 * RFC 8785 §3.2.2.3 defers number serialization to ECMA-262 §7.1.12.1 ("Note 2"
 * enhancement included). That algorithm has two halves:
 *
 *  1. Find the *shortest* decimal digit string that round-trips back to the
 *     same double — ECMA-262's "k is as small as possible".
 *  2. Lay those digits out, choosing fixed or exponential notation by where the
 *     decimal point falls.
 *
 * PHP already has a correctly rounded `dtoa` behind `sprintf('%.*E')`, so the
 * first half is a search for the smallest precision that survives a round-trip.
 * The search is sound because round-tripping is monotonic in precision: if `p`
 * digits round-trip, so does every precision above it.
 *
 * The second half is where PHP's own `json_encode` diverges from ECMAScript —
 * it writes `-0`, `1.0e-6` and `1.0e+21` where ECMAScript writes `0`,
 * `0.000001` and `1e+21` — so it is spelled out here rather than delegated.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8785#section-3.2.2.3
 */
final class Es6NumberFormatter
{
    /**
     * Decimal point positions in `[MIN_FIXED_POINT, MAX_FIXED_POINT]` are written
     * in fixed notation; everything outside goes exponential.
     *
     * `n` is the position of the decimal point relative to the digit string, so
     * `1e21` (n = 22) is the first value written as an exponent, and `1e-7`
     * (n = -6) the first on the small end. PHP switches to exponential notation
     * well before either boundary.
     */
    private const MAX_FIXED_POINT = 21;

    private const MIN_FIXED_POINT = -5;

    /**
     * No double needs more than 17 significant decimal digits to round-trip.
     */
    private const MAX_SIGNIFICANT_DIGITS = 17;

    /**
     * Serialize a double exactly as ECMAScript's `String(value)` would.
     *
     * @throws CanonicalizationException if the value is NaN or infinite — RFC 8785
     *                                   §3.2.2.3 requires terminating on both
     */
    public static function format(float $value): string
    {
        if (is_nan($value)) {
            throw new CanonicalizationException('NaN cannot be serialized as JSON');
        }

        if (is_infinite($value)) {
            throw new CanonicalizationException('Infinity cannot be serialized as JSON');
        }

        // Covers -0.0 too: ECMAScript serializes negative zero unsigned.
        if ($value === 0.0) {
            return '0';
        }

        $sign = '';
        if ($value < 0.0) {
            $sign = '-';
            $value = -$value;
        }

        [$digits, $point] = self::shortestDigits($value);

        return $sign.self::layout($digits, $point);
    }

    /**
     * The shortest round-tripping digit string, with the position of the decimal
     * point relative to it (ECMA-262's `s` and `n`).
     *
     * The digit string never carries a trailing zero: a trailing zero would mean
     * the next precision down represents the same decimal value and would have
     * round-tripped first.
     *
     * @return array{string, int}
     */
    private static function shortestDigits(float $value): array
    {
        $scientific = sprintf('%.'.(self::MAX_SIGNIFICANT_DIGITS - 1).'E', $value);

        for ($precision = 1; $precision < self::MAX_SIGNIFICANT_DIGITS; $precision++) {
            $candidate = sprintf('%.'.($precision - 1).'E', $value);

            if ((float) $candidate === $value) {
                $scientific = $candidate;
                break;
            }
        }

        [$mantissa, $exponent] = explode('E', $scientific, 2);

        return [str_replace('.', '', $mantissa), (int) $exponent + 1];
    }

    /**
     * ECMA-262 §7.1.12.1 steps 6-10: place `$digits` around a decimal point that
     * sits `$point` digits in from the left.
     */
    private static function layout(string $digits, int $point): string
    {
        $length = strlen($digits);

        // 123 -> "123", 12 with point 4 -> "1200"
        if ($length <= $point && $point <= self::MAX_FIXED_POINT) {
            return $digits.str_repeat('0', $point - $length);
        }

        // 1234 with point 2 -> "12.34"
        if ($point > 0 && $point <= self::MAX_FIXED_POINT) {
            return substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        // 1 with point -5 -> "0.000001"
        if ($point >= self::MIN_FIXED_POINT && $point <= 0) {
            return '0.'.str_repeat('0', -$point).$digits;
        }

        $exponent = $point - 1;
        $suffix = 'e'.($exponent < 0 ? '-' : '+').abs($exponent);

        return $length === 1
            ? $digits.$suffix
            : $digits[0].'.'.substr($digits, 1).$suffix;
    }
}
