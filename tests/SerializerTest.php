<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;
use Accredify\JsonCanonicalize\Serializer;

uses()->group('serializer');

it('writes the literals', function () {
    $serializer = new Serializer;

    expect($serializer->serialize(null))->toBe('null')
        ->and($serializer->serialize(true))->toBe('true')
        ->and($serializer->serialize(false))->toBe('false');
});

it('escapes only what RFC 8785 §3.2.2.2 requires', function () {
    // Control characters become \b \t \n \f \r where JSON names them and \uhhhh
    // otherwise; " and \ are escaped; / and DEL (U+007F) are left alone.
    expect((new Serializer)->serialize("\x00\x01\x08\x09\x0A\x0B\x0C\x0D\x1F\"\\/\x7F"))
        ->toBe('"\u0000\u0001\b\t\n\u000b\f\r\u001f\"\\\\/'."\x7F".'"');
});

it('writes control-character escapes in lowercase hex', function () {
    expect((new Serializer)->serialize("\x0E\x1A"))->toBe('"\u000e\u001a"');
});

it('leaves non-ASCII as UTF-8', function () {
    expect((new Serializer)->serialize("\u{20AC}\u{1F602}"))->toBe('"'."\u{20AC}\u{1F602}".'"');
});

it('sorts property names by UTF-16 code unit, not by code point', function () {
    // U+1F602 is the larger code point, but its UTF-16 form starts at the
    // surrogate U+D83D and so sorts below U+FB33. This is the ordering the RFC
    // singles out as the reason sorting must be done in UTF-16.
    $object = (object) ["\u{1F602}" => 1, "\u{FB33}" => 2, 'a' => 3];

    expect((new Serializer)->serialize($object))
        ->toBe('{"a":3,"'."\u{1F602}".'":1,"'."\u{FB33}".'":2}');
});

it('sorts a shorter name before a longer one that extends it', function () {
    $object = (object) ['ab' => 1, 'a' => 2, 'aa' => 3, '' => 4];

    expect((new Serializer)->serialize($object))->toBe('{"":4,"a":2,"aa":3,"ab":1}');
});

it('sorts nested objects too, but never reorders arrays', function () {
    expect((new Serializer)->serialize((object) ['z' => [3, 1, 2], 'a' => (object) ['y' => 1, 'x' => 2]]))
        ->toBe('{"a":{"x":2,"y":1},"z":[3,1,2]}');
});

it('serializes a PHP list as an array and a map as an object', function () {
    $serializer = new Serializer;

    expect($serializer->serialize([1, 2, 3]))->toBe('[1,2,3]')
        ->and($serializer->serialize(['b' => 1, 'a' => 2]))->toBe('{"a":2,"b":1}')
        ->and($serializer->serialize([]))->toBe('[]')
        ->and($serializer->serialize(new stdClass))->toBe('{}');
});

it('casts integer array keys back to property names', function () {
    expect((new Serializer)->serialize([10 => 'b', 1 => 'a', 'd' => 'c']))
        ->toBe('{"1":"a","10":"b","d":"c"}');
});

it('serializes a PHP integer as a double', function () {
    $serializer = new Serializer;

    expect($serializer->serialize(42))->toBe('42')
        ->and($serializer->serialize(9007199254740993))->toBe('9007199254740992');
});

it('unwraps a JsonSerializable', function () {
    $value = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return ['b' => 1, 'a' => 2];
        }
    };

    expect((new Serializer)->serialize($value))->toBe('{"a":2,"b":1}');
});

it('rejects a value it cannot represent', function () {
    (new Serializer)->serialize(fopen('php://memory', 'r'));
})->throws(CanonicalizationException::class, 'Cannot canonicalize a value of type resource');

it('rejects string data that is not valid UTF-8', function () {
    (new Serializer)->serialize("\xC3\x28");
})->throws(CanonicalizationException::class, 'String data is not valid UTF-8');

it('rejects a property name that is not valid UTF-8', function () {
    (new Serializer)->serialize(["\xC3\x28" => 1]);
})->throws(CanonicalizationException::class, 'A property name is not valid UTF-8');

it('bounds nesting depth', function () {
    $nested = [];
    for ($i = 0; $i < 40; $i++) {
        $nested = [$nested];
    }

    expect(fn () => (new Serializer(maxDepth: 32))->serialize($nested))
        ->toThrow(CanonicalizationException::class, 'Maximum nesting depth of 32 exceeded');

    expect((new Serializer(maxDepth: 64))->serialize($nested))->toBeString();
});
