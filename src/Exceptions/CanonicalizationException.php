<?php

namespace Accredify\JsonCanonicalize\Exceptions;

use RuntimeException;

/**
 * Thrown when input cannot be canonicalized under RFC 8785.
 *
 * JCS fails closed: anything that could make two implementations disagree on
 * the output bytes — invalid JSON, duplicate property names, lone surrogates,
 * NaN/Infinity, numbers outside the IEEE-754 range — raises this rather than
 * producing a best-effort serialization.
 */
class CanonicalizationException extends RuntimeException
{
    /**
     * A parse failure, tagged with the byte offset it was detected at.
     */
    public static function atOffset(string $message, int $offset): self
    {
        return new self(sprintf('%s at offset %d', $message, $offset));
    }
}
