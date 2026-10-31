<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\JCS;
use Accredify\JsonCanonicalize\Tests\Conformance\Harness;
use Accredify\JsonCanonicalize\Tests\Conformance\Vector;

/*
|--------------------------------------------------------------------------
| RFC 8785 conformance — cyberphone input/output vectors
|--------------------------------------------------------------------------
| Runs the official cyberphone/json-canonicalization vectors (the
| tests/json-canonicalization submodule) against this package. This suite
| GATES CI: every vector passes, there is no allowlist.
|
| Each vector is checked twice — against output/ as UTF-8 text, and against
| outhex/ decoded from hexadecimal. The second comparison is the one that
| catches a checkout or a platform that has quietly rewritten the bytes.
*/

uses()->group('jcs');

dataset('vectors', function () {
    foreach (Harness::fromDefaultLocation()->vectors() as $vector) {
        yield $vector->describe() => [$vector];
    }
});

it('canonicalizes the cyberphone vectors', function (Vector $vector) {
    expect((new JCS)->canonicalize($vector->loadInput()))
        ->toBe($vector->loadExpected());
})->with('vectors');

it('emits the exact bytes the cyberphone vectors record in hexadecimal', function (Vector $vector) {
    expect((new JCS)->canonicalize($vector->loadInput()))
        ->toBe($vector->loadExpectedFromHex());
})->with('vectors');

it('is idempotent — canonicalizing canonical output changes nothing', function (Vector $vector) {
    $canonical = (new JCS)->canonicalize($vector->loadInput());

    expect((new JCS)->canonicalize($canonical))->toBe($canonical);
})->with('vectors');
