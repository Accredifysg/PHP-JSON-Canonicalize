<?php

declare(strict_types=1);

namespace Accredify\JsonCanonicalize\Tests\Conformance;

use RuntimeException;

/**
 * One input/output pair from the cyberphone conformance suite.
 *
 * The suite's testdata directory holds three parallel directories keyed on the
 * same file name:
 *
 *   input/<name>.json   non-canonical JSON, formatted and ordered freely
 *   output/<name>.json  the canonical form, as UTF-8
 *   outhex/<name>.txt   the same bytes in hexadecimal, space separated
 *
 * The hex copy exists so an implementation cannot pass by accident on a
 * platform that rewrites line endings or re-encodes the file — it is compared
 * separately by the harness.
 */
final class Vector
{
    public function __construct(
        public readonly string $name,
        public readonly string $inputPath,
        public readonly string $outputPath,
        public readonly string $hexPath,
    ) {}

    public function loadInput(): string
    {
        return $this->read($this->inputPath);
    }

    public function loadExpected(): string
    {
        return $this->read($this->outputPath);
    }

    /**
     * The expected bytes, decoded from the hexadecimal copy.
     */
    public function loadExpectedFromHex(): string
    {
        $hex = preg_replace('/\s+/', '', $this->read($this->hexPath)) ?? '';

        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new RuntimeException("Malformed hexadecimal in {$this->hexPath}");
        }

        return $bytes;
    }

    public function describe(): string
    {
        return $this->name;
    }

    private function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fixture not found at {$path}. Run `git submodule update --init --recursive`.");
        }

        return (string) file_get_contents($path);
    }
}
