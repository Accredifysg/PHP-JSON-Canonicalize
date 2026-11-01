<?php

declare(strict_types=1);

use Accredify\JsonCanonicalize\Tests\Conformance\NumberSequence;

/*
|--------------------------------------------------------------------------
| RFC 8785 conformance — ECMAScript number serialization
|--------------------------------------------------------------------------
| The cyberphone suite's exhaustive number check is a 4 GB file of 100 million
| IEEE-754 values and their expected serializations. Rather than download it,
| this regenerates the sequence locally (see NumberSequence) and compares the
| SHA-256 of each published prefix. A match means every line agreed.
|
| Up to a million lines runs by default (~11s). Raise the ceiling with
| JCS_NUMBER_LINES to run the larger prefixes:
|
|   JCS_NUMBER_LINES=10000000 composer test:jcs     # ~2 minutes
|   JCS_NUMBER_LINES=100000000 composer test:jcs    # ~20 minutes
*/

uses()->group('jcs');

const DEFAULT_NUMBER_LINES = 1_000_000;

dataset('number-prefixes', function () {
    $ceiling = (int) (getenv('JCS_NUMBER_LINES') ?: DEFAULT_NUMBER_LINES);

    foreach (NumberSequence::PUBLISHED_CHECKSUMS as $lines => $checksum) {
        if ($lines <= $ceiling) {
            yield sprintf('first %s lines', number_format($lines)) => [$lines, $checksum];
        }
    }
});

it('reproduces the published ES6 number checksums', function (int $lines, string $checksum) {
    expect((new NumberSequence)->checksum($lines))->toBe($checksum);
})->with('number-prefixes');
