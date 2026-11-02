<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\JsonObject;
use Accredify\JsonCanonicalize\JsonParser;
use Accredify\JsonCanonicalize\Serializer;

uses()->group('object');

it('removes a member', function () {
    $object = new JsonObject;
    $object->set('a', 1.0);
    $object->set('b', 2.0);

    $object->remove('a');

    expect($object->has('a'))->toBeFalse()
        ->and($object->get('a'))->toBeNull()
        ->and($object->count())->toBe(1)
        ->and(array_keys($object->all()))->toBe(['b']);
});

it('treats removing an absent member as a no-op', function () {
    $object = new JsonObject;
    $object->set('a', 1.0);

    $object->remove('nope');

    expect($object->count())->toBe(1)
        ->and($object->has('a'))->toBeTrue();
});

it('removes a member whose name PHP folds to an integer key', function () {
    // "10" is a canonical decimal string, so it lives in the members array as
    // the integer 10. remove() has to find it by the name it came in under.
    $object = new JsonObject;
    $object->set('10', 'ten');
    $object->set('x', 'ex');

    expect($object->has('10'))->toBeTrue();

    $object->remove('10');

    expect($object->has('10'))->toBeFalse()
        ->and($object->count())->toBe(1);
});

it('removes a member whose name begins with a NUL byte', function () {
    // The case stdClass cannot represent at all, and the reason this class
    // exists. Removal must work there too.
    $name = "\x00a";

    $object = new JsonObject;
    $object->set($name, 1.0);
    $object->set('b', 2.0);

    $object->remove($name);

    expect($object->has($name))->toBeFalse()
        ->and($object->count())->toBe(1);
});

it('drops the member from the canonical output', function () {
    /** @var JsonObject $parsed */
    $parsed = (new JsonParser)->parse('{"b":2,"a":1,"proof":{"z":3}}');

    $parsed->remove('proof');

    expect((new Serializer)->serialize($parsed))->toBe('{"a":1,"b":2}');
});

it('re-adds a removed name at the end, as set() always does', function () {
    // Property order is the insertion order; canonicalization sorts anyway, so
    // this pins the data structure rather than the output.
    $object = new JsonObject;
    $object->set('a', 1.0);
    $object->set('b', 2.0);

    $object->remove('a');
    $object->set('a', 3.0);

    expect(array_keys($object->all()))->toBe(['b', 'a'])
        ->and($object->get('a'))->toBe(3.0);
});

it('gives a clone an independent member list', function () {
    // Members live in a PHP array, so `clone` copies them by value. Callers
    // editing a parsed tree rely on this to leave the original intact.
    $original = new JsonObject;
    $original->set('a', 1.0);
    $original->set('b', 2.0);

    $copy = clone $original;
    $copy->remove('a');
    $copy->set('c', 3.0);

    expect($original->has('a'))->toBeTrue()
        ->and($original->has('c'))->toBeFalse()
        ->and($copy->has('a'))->toBeFalse()
        ->and($copy->has('c'))->toBeTrue();
});
