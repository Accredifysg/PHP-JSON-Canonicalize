<?php

namespace Accredify\JsonCanonicalize\Contracts;

interface Canonicalizer
{
    /**
     * Canonicalize a JSON document per the JSON Canonicalization Scheme (RFC 8785).
     *
     * @param  string  $json  A JSON document encoded as UTF-8
     * @return string The canonical serialization, as UTF-8 bytes
     */
    public function canonicalize(string $json): string;
}
