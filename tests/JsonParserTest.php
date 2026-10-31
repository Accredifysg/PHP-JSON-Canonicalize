<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;
use Accredify\JsonCanonicalize\JsonParser;

uses()->group('parser');

it('parses the primitive types', function () {
    $parser = new JsonParser;

    expect($parser->parse('null'))->toBeNull()
        ->and($parser->parse('true'))->toBeTrue()
        ->and($parser->parse('false'))->toBeFalse()
        ->and($parser->parse('"hi"'))->toBe('hi')
        ->and($parser->parse('  1  '))->toBe(1.0);
});

it('parses every number as a double', function () {
    $parser = new JsonParser;

    // RFC 8785 §3.1 — JSON numbers are IEEE-754 doubles, integers included.
    expect($parser->parse('42'))->toBe(42.0)
        ->and($parser->parse('-0.5'))->toBe(-0.5)
        ->and($parser->parse('2e-3'))->toBe(0.002)
        ->and($parser->parse('1E30'))->toBe(1.0e30)
        ->and($parser->parse('0'))->toBe(0.0);
});

it('rounds integers that exceed double precision, as ECMAScript does', function () {
    expect((new JsonParser)->parse('9007199254740993'))->toBe(9007199254740992.0);
});

it('keeps object property names as strings', function () {
    /** @var stdClass $parsed */
    $parsed = (new JsonParser)->parse('{"1":"a","10":"b","":"c"}');

    // Reflection is the only API that hands back the true property names —
    // get_object_vars() and an array cast fold "1" and "10" into integers.
    $names = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionObject($parsed))->getProperties(),
    );

    expect($names)->toBe(['1', '10', '']);
});

it('tells an empty array from an empty object', function () {
    $parser = new JsonParser;

    expect($parser->parse('[]'))->toBe([])
        ->and($parser->parse('{}'))->toBeInstanceOf(stdClass::class);
});

it('resolves string escapes', function () {
    expect((new JsonParser)->parse('"\\"\\\\\\/\\b\\f\\n\\r\\t"'))
        ->toBe("\"\\/\x08\x0C\n\r\t");
});

it('combines a surrogate pair into one code point', function () {
    expect((new JsonParser)->parse('"\\ud83d\\ude02"'))->toBe("\u{1F602}");
});

it('rejects duplicate property names', function () {
    // RFC 8785 §3.1 — duplicates make the canonical form ambiguous.
    (new JsonParser)->parse('{"a":1,"a":2}');
})->throws(CanonicalizationException::class, 'Duplicate property name "a"');

it('rejects lone surrogates', function (string $json) {
    (new JsonParser)->parse($json);
})->with([
    'high, unpaired' => ['"\\ud83d"'],
    'low, unpaired' => ['"\\ude02"'],
    'high, then not a low' => ['"\\ud83d\\u0041"'],
])->throws(CanonicalizationException::class);

it('rejects numbers that overflow double precision', function () {
    (new JsonParser)->parse('1e400');
})->throws(CanonicalizationException::class, 'overflows IEEE-754 double precision');

it('underflows to zero, as ECMAScript does', function () {
    expect((new JsonParser)->parse('1e-400'))->toBe(0.0);
});

it('rejects malformed JSON', function (string $json) {
    (new JsonParser)->parse($json);
})->with([
    'empty input' => [''],
    'trailing data' => ['{} {}'],
    'trailing comma in an object' => ['{"a":1,}'],
    'trailing comma in an array' => ['[1,]'],
    'unquoted property name' => ['{a:1}'],
    'single quotes' => ["'a'"],
    'missing colon' => ['{"a" 1}'],
    'unterminated string' => ['"abc'],
    'unterminated object' => ['{"a":1'],
    'unterminated array' => ['[1'],
    'leading zero' => ['01'],
    'leading plus' => ['+1'],
    'bare decimal point' => ['1.'],
    'empty exponent' => ['1e'],
    'NaN literal' => ['NaN'],
    'Infinity literal' => ['Infinity'],
    'truncated keyword' => ['tru'],
    'invalid escape' => ['"\\x41"'],
    'short unicode escape' => ['"\\u00"'],
    'non-hex unicode escape' => ['"\\uzzzz"'],
])->throws(CanonicalizationException::class);

it('rejects a string that ends mid-escape', function () {
    (new JsonParser)->parse('"abc\\');
})->throws(CanonicalizationException::class, 'Unterminated escape sequence');

it('rejects an unescaped control character in a string', function () {
    (new JsonParser)->parse("\"a\nb\"");
})->throws(CanonicalizationException::class, 'Unescaped control character U+000A in string');

it('rejects input that is not valid UTF-8', function () {
    (new JsonParser)->parse("\"\xC3\x28\"");
})->throws(CanonicalizationException::class, 'Input is not valid UTF-8');

it('reports the offset a parse failed at', function () {
    expect(fn () => (new JsonParser)->parse('{"a":1,"b":}'))
        ->toThrow(CanonicalizationException::class, 'at offset 11');
});

it('bounds nesting depth', function () {
    $json = str_repeat('[', 40).str_repeat(']', 40);

    expect(fn () => (new JsonParser(maxDepth: 32))->parse($json))
        ->toThrow(CanonicalizationException::class, 'Maximum nesting depth of 32 exceeded');

    expect((new JsonParser(maxDepth: 64))->parse($json))->toBeArray();
});

it('accepts the whitespace RFC 8259 allows', function () {
    expect((new JsonParser)->parse(" \t\r\n{ \"a\" : [ 1 , 2 ] }\t\n"))
        ->toBeInstanceOf(stdClass::class);
});
