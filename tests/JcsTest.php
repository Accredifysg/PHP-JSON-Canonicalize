<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;
use Accredify\JsonCanonicalize\JCS;

uses()->group('jcs-api');

/*
|--------------------------------------------------------------------------
| The worked example from RFC 8785 §3.2
|--------------------------------------------------------------------------
| The specification carries one document all the way through: §3.2.2 parses it,
| §3.2.3 sorts it, and §3.2.4 prints the resulting bytes in hexadecimal. All
| three are checked here, the last one byte for byte.
|
| Both constants are nowdocs, so every backslash below is a literal backslash —
| the text is exactly what the RFC prints.
*/

const RFC_EXAMPLE = <<<'JSON'
    {
      "numbers": [333333333.33333329, 1E30, 4.50,
                  2e-3, 0.000000000000000000000000001],
      "string": "\u20ac$\u000F\u000aA'\u0042\u0022\u005c\\\"\/",
      "literals": [null, true, false]
    }
    JSON;

const RFC_EXAMPLE_CANONICAL = <<<'JSON'
    {"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA'B\"\\\\\"/"}
    JSON;

const RFC_EXAMPLE_HEX = <<<'HEX'
    7b 22 6c 69 74 65 72 61 6c 73 22 3a 5b 6e 75 6c 6c 2c 74 72
    75 65 2c 66 61 6c 73 65 5d 2c 22 6e 75 6d 62 65 72 73 22 3a
    5b 33 33 33 33 33 33 33 33 33 2e 33 33 33 33 33 33 33 2c 31
    65 2b 33 30 2c 34 2e 35 2c 30 2e 30 30 32 2c 31 65 2d 32 37
    5d 2c 22 73 74 72 69 6e 67 22 3a 22 e2 82 ac 24 5c 75 30 30
    30 66 5c 6e 41 27 42 5c 22 5c 5c 5c 5c 5c 22 2f 22 7d
    HEX;

it('canonicalizes the RFC 8785 worked example', function () {
    expect((new JCS)->canonicalize(RFC_EXAMPLE))->toBe(RFC_EXAMPLE_CANONICAL);
});

it('emits the UTF-8 bytes RFC 8785 §3.2.4 records', function () {
    $expected = hex2bin((string) preg_replace('/\s+/', '', RFC_EXAMPLE_HEX));

    expect((new JCS)->canonicalize(RFC_EXAMPLE))->toBe($expected);
});

it('sorts the property names RFC 8785 §3.2.3 uses to demonstrate ordering', function () {
    $json = <<<'JSON'
        {
          "\u20ac": "Euro Sign",
          "\r": "Carriage Return",
          "\ufb33": "Hebrew Letter Dalet With Dagesh",
          "1": "One",
          "\ud83d\ude00": "Emoji: Grinning Face",
          "\u0080": "Control",
          "\u00f6": "Latin Small Letter O With Diaeresis"
        }
        JSON;

    /** @var array<string, string> $decoded */
    $decoded = json_decode((new JCS)->canonicalize($json), true, flags: JSON_THROW_ON_ERROR);

    expect(array_values($decoded))->toBe([
        'Carriage Return',
        'One',
        'Control',
        'Latin Small Letter O With Diaeresis',
        'Euro Sign',
        'Emoji: Grinning Face',
        'Hebrew Letter Dalet With Dagesh',
    ]);
});

/*
|--------------------------------------------------------------------------
| The public surface
|--------------------------------------------------------------------------
*/

it('canonicalizes a document end to end', function () {
    expect((new JCS)->canonicalize('{"b":1,"a":[2,3.0]}'))->toBe('{"a":[2,3],"b":1}');
});

it('canonicalizes a value that was never JSON text', function () {
    expect((new JCS)->canonicalizeValue(['b' => 1, 'a' => [2, 3.0]]))->toBe('{"a":[2,3],"b":1}');
});

it('agrees whether the document was parsed or built', function () {
    $jcs = new JCS;
    $document = [
        '@context' => ['https://www.w3.org/ns/credentials/v2'],
        'type' => ['VerifiableCredential'],
        'issuer' => 'did:example:1',
    ];

    expect($jcs->canonicalizeValue($document))
        ->toBe($jcs->canonicalize((string) json_encode($document)));
});

it('is idempotent', function () {
    $jcs = new JCS;
    $once = $jcs->canonicalize(RFC_EXAMPLE);

    expect($jcs->canonicalize($once))->toBe($once);
});

it('preserves unnormalized Unicode as it stands', function () {
    // RFC 8785 §3.1 — JCS never applies Unicode normalization, so a combining
    // sequence stays a combining sequence rather than becoming U+00C5.
    expect((new JCS)->canonicalize('{"a":"A\\u030a"}'))->toBe('{"a":"A'."\u{030A}".'"}');
});

it('canonicalizes a property name that begins with a NUL byte', function () {
    // Legal JSON that PHP cannot hold as a stdClass property; the JS reference
    // canonicalizer handles it, so this package must too.
    expect((new JCS)->canonicalize('{"b":1,"\\u0000a":2}'))->toBe('{"\u0000a":2,"b":1}');
});

it('propagates a parse failure', function () {
    (new JCS)->canonicalize('{"a":1,"a":2}');
})->throws(CanonicalizationException::class, 'Duplicate property name "a"');
