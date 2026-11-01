<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\Es6NumberFormatter;
use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;

uses()->group('numbers');

/**
 * Reinterpret a raw IEEE-754 bit pattern as a double, so the RFC's samples can
 * be written exactly as the RFC writes them.
 */
function doubleFromHex(string $hex): float
{
    /** @var array{1: float} $unpacked */
    $unpacked = unpack('E', (string) hex2bin($hex));

    return $unpacked[1];
}

/*
|--------------------------------------------------------------------------
| RFC 8785 Appendix B
|--------------------------------------------------------------------------
| "Number Serialization Samples" — the table of IEEE-754 bit patterns and
| their required JSON representation, transcribed verbatim.
*/

dataset('appendix-b', [
    'zero' => ['0000000000000000', '0'],
    'minus zero' => ['8000000000000000', '0'],
    'min pos number' => ['0000000000000001', '5e-324'],
    'min neg number' => ['8000000000000001', '-5e-324'],
    'max pos number' => ['7fefffffffffffff', '1.7976931348623157e+308'],
    'max neg number' => ['ffefffffffffffff', '-1.7976931348623157e+308'],
    'max pos int' => ['4340000000000000', '9007199254740992'],
    'max neg int' => ['c340000000000000', '-9007199254740992'],
    '~2**68' => ['4430000000000000', '295147905179352830000'],
    'below 1e+23' => ['44b52d02c7e14af5', '9.999999999999997e+22'],
    '1e+23' => ['44b52d02c7e14af6', '1e+23'],
    'above 1e+23' => ['44b52d02c7e14af7', '1.0000000000000001e+23'],
    'below 1e+21 (two)' => ['444b1ae4d6e2ef4e', '999999999999999700000'],
    'below 1e+21 (one)' => ['444b1ae4d6e2ef4f', '999999999999999900000'],
    '1e+21' => ['444b1ae4d6e2ef50', '1e+21'],
    'below 1e-6' => ['3eb0c6f7a0b5ed8c', '9.999999999999997e-7'],
    '1e-6' => ['3eb0c6f7a0b5ed8d', '0.000001'],
    '333333333.3333332' => ['41b3de4355555553', '333333333.3333332'],
    '333333333.33333325' => ['41b3de4355555554', '333333333.33333325'],
    '333333333.3333333' => ['41b3de4355555555', '333333333.3333333'],
    '333333333.3333334' => ['41b3de4355555556', '333333333.3333334'],
    '333333333.33333343' => ['41b3de4355555557', '333333333.33333343'],
    'small negative' => ['becbf647612f3696', '-0.0000033333333333333333'],
    'round to even' => ['43143ff3c1cb0959', '1424953923781206.2'],
]);

it('serializes the RFC 8785 Appendix B samples', function (string $hex, string $expected) {
    expect(Es6NumberFormatter::format(doubleFromHex($hex)))->toBe($expected);
})->with('appendix-b');

/*
|--------------------------------------------------------------------------
| Where PHP's own json_encode diverges
|--------------------------------------------------------------------------
| PHP computes the shortest round-tripping digits already (serialize_precision
| = -1), so what is left is layout: PHP signs negative zero, switches to
| exponential notation earlier than ECMAScript on the small end, and writes a
| redundant ".0" before the exponent.
*/

dataset('php-divergences', [
    'negative zero is unsigned' => [-0.0, '0', '-0'],
    'the small fixed boundary' => [1e-6, '0.000001', '1.0e-6'],
    'just below the small boundary' => [5e-7, '5e-7', '5.0e-7'],
    'the large fixed boundary' => [1e21, '1e+21', '1.0e+21'],
]);

it('formats the cases PHP json_encode gets wrong', function (float $value, string $expected, string $phpEncodes) {
    expect(Es6NumberFormatter::format($value))->toBe($expected)
        ->and(json_encode($value))->toBe($phpEncodes);
})->with('php-divergences');

it('stays in fixed notation across the boundaries', function () {
    expect(Es6NumberFormatter::format(1e-7))->toBe('1e-7')
        ->and(Es6NumberFormatter::format(1e-6))->toBe('0.000001')
        ->and(Es6NumberFormatter::format(1e20))->toBe('100000000000000000000')
        ->and(Es6NumberFormatter::format(1e21))->toBe('1e+21');
});

it('keeps whole numbers whole', function (float $value, string $expected) {
    expect(Es6NumberFormatter::format($value))->toBe($expected);
})->with([
    [10.0, '10'],
    [100.0, '100'],
    [1000.0, '1000'],
    [56.0, '56'],
    [4.50, '4.5'],
    [-1.0, '-1'],
]);

it('keeps every significant digit', function () {
    expect(Es6NumberFormatter::format(0.3333333333333333))->toBe('0.3333333333333333')
        ->and(Es6NumberFormatter::format(333333333.33333329))->toBe('333333333.3333333')
        ->and(Es6NumberFormatter::format(1.5e300))->toBe('1.5e+300')
        ->and(Es6NumberFormatter::format(9007199254740992.0))->toBe('9007199254740992');
});

it('rejects NaN', function () {
    Es6NumberFormatter::format(NAN);
})->throws(CanonicalizationException::class, 'NaN cannot be serialized as JSON');

it('rejects infinity', function (float $value) {
    Es6NumberFormatter::format($value);
})->with([[INF], [-INF]])->throws(CanonicalizationException::class, 'Infinity cannot be serialized as JSON');
