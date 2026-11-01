<?php

declare(strict_types=1);

namespace Accredify\JsonCanonicalize\Tests\Conformance;

use RuntimeException;

/**
 * Enumerates the cyberphone conformance vectors.
 *
 * The suite (tests/json-canonicalization/) is a git submodule pointing at
 * https://github.com/cyberphone/json-canonicalization — the de facto
 * conformance suite for RFC 8785, since IETF publishes no test suite of its
 * own. There is no manifest file: the vectors are simply every file in
 * testdata/input/, matched by name against testdata/output/ and testdata/outhex/.
 *
 * This class is intentionally narrow: it just pairs the files up. Execution
 * lives in the Pest harness under tests/Conformance/Vectors/.
 */
final class Harness
{
    public function __construct(
        private readonly string $testdataRoot,
    ) {
        if (! is_dir($testdataRoot)) {
            throw new RuntimeException(sprintf(
                'Conformance testdata not found at %s. Run `git submodule update --init --recursive`.',
                $testdataRoot,
            ));
        }
    }

    public static function fromDefaultLocation(): self
    {
        return new self(__DIR__.'/../json-canonicalization/testdata');
    }

    /**
     * @return iterable<string, Vector>
     */
    public function vectors(): iterable
    {
        $inputs = glob($this->testdataRoot.'/input/*.json');
        if ($inputs === false || $inputs === []) {
            throw new RuntimeException('No conformance vectors found. Run `git submodule update --init --recursive`.');
        }

        sort($inputs);

        foreach ($inputs as $inputPath) {
            $name = basename($inputPath, '.json');

            yield $name => new Vector(
                name: $name,
                inputPath: $inputPath,
                outputPath: $this->testdataRoot.'/output/'.$name.'.json',
                hexPath: $this->testdataRoot.'/outhex/'.$name.'.txt',
            );
        }
    }
}
