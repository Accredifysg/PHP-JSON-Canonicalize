<?php

namespace Accredify\JsonCanonicalize;

use Accredify\JsonCanonicalize\Contracts\Canonicalizer;
use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;

/**
 * The JSON Canonicalization Scheme (JCS), RFC 8785.
 *
 * JCS turns any JSON document into the one byte sequence that every conforming
 * implementation agrees on, so the result can be hashed and signed:
 *
 * ```
 * JSON text  ──►  JsonParser  ──►  Serializer  ──►  canonical UTF-8
 * ```
 *
 * ```php
 * echo (new JCS)->canonicalize('{"b":1,"a":[2,3.0]}');
 * // {"a":[2,3],"b":1}
 * ```
 *
 * @see https://www.rfc-editor.org/rfc/rfc8785
 */
final class JCS implements Canonicalizer
{
    public function __construct(
        private readonly JsonParser $parser = new JsonParser,
        private readonly Serializer $serializer = new Serializer,
    ) {}

    /**
     * Canonicalize a JSON document.
     *
     * @param  string  $json  A JSON document encoded as UTF-8
     * @return string The canonical serialization, as UTF-8 bytes
     *
     * @throws CanonicalizationException if the input is not strictly valid I-JSON
     */
    public function canonicalize(string $json): string
    {
        return $this->serializer->serialize($this->parser->parse($json));
    }

    /**
     * Canonicalize an already-decoded value — a PHP array, a `stdClass`, a
     * `JsonSerializable`, or any scalar.
     *
     * Use this when the document was built in PHP rather than parsed, and skip
     * the `json_encode` round-trip. Note that a PHP array is ambiguous where
     * JSON is not: an empty array serializes as `[]`, so an empty JSON object
     * has to be written as `new stdClass`.
     *
     * @throws CanonicalizationException if the value cannot be represented as I-JSON
     */
    public function canonicalizeValue(mixed $value): string
    {
        return $this->serializer->serialize($value);
    }
}
