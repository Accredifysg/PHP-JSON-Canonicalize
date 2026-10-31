<?php

namespace Accredify\JsonCanonicalize;

use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;
use JsonSerializable;
use stdClass;

/**
 * The JCS writer: RFC 8785 §3.2, applied to an in-memory value.
 *
 * Three rules do the work.
 *
 *  - **No whitespace** between tokens (§3.2.1).
 *  - **Property names sorted as arrays of UTF-16 code units** (§3.2.3), which is
 *    *not* UTF-8 byte order: a surrogate pair starts at U+D800, so U+1F602 (😂)
 *    sorts before U+FB33 (דּ) even though its code point is larger.
 *  - **Minimal string escaping** (§3.2.2.2) and **ECMAScript number formatting**
 *    (§3.2.2.3, delegated to {@see Es6NumberFormatter}).
 *
 * Besides what {@see JsonParser} produces, this accepts native PHP values so a
 * document that was built programmatically can be canonicalized without a
 * round-trip through `json_encode`. A PHP array serializes as a JSON array when
 * it is a list and as a JSON object otherwise, which leaves `[]` meaning an
 * empty array — pass `new stdClass` for an empty JSON object.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8785#section-3.2
 */
final class Serializer
{
    /**
     * Characters that must be escaped inside a JSON string: the quote, the
     * backslash, and every C0 control character.
     */
    private const MUST_ESCAPE = "\"\\\0\1\2\3\4\5\6\7\10\11\12\13\14\15\16\17\20\21\22\23\24\25\26\27\30\31\32\33\34\35\36\37";

    public function __construct(
        private readonly int $maxDepth = JsonParser::DEFAULT_MAX_DEPTH
    ) {}

    /**
     * Serialize a value to its canonical UTF-8 form.
     *
     * @throws CanonicalizationException if the value cannot be represented as I-JSON
     */
    public function serialize(mixed $value): string
    {
        return $this->write($value, 1);
    }

    private function write(mixed $value, int $depth): string
    {
        if ($depth > $this->maxDepth) {
            throw new CanonicalizationException(sprintf('Maximum nesting depth of %d exceeded', $this->maxDepth));
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $this->writeString($value),
            // RFC 8785 §3.1: every JSON number is an IEEE-754 double. A PHP
            // integer past 2**53 is rounded, exactly as ECMAScript would.
            is_int($value) => Es6NumberFormatter::format((float) $value),
            is_float($value) => Es6NumberFormatter::format($value),
            is_array($value) => array_is_list($value)
                ? $this->writeArray($value, $depth)
                : $this->writeObject($this->arrayEntries($value), $depth),
            $value instanceof stdClass => $this->writeObject($this->objectEntries($value), $depth),
            $value instanceof JsonSerializable => $this->write($value->jsonSerialize(), $depth + 1),
            default => throw new CanonicalizationException(sprintf(
                'Cannot canonicalize a value of type %s',
                get_debug_type($value),
            )),
        };
    }

    /**
     * @param  list<mixed>  $elements
     */
    private function writeArray(array $elements, int $depth): string
    {
        $parts = [];
        foreach ($elements as $element) {
            $parts[] = $this->write($element, $depth + 1);
        }

        return '['.implode(',', $parts).']';
    }

    /**
     * @param  list<array{string, mixed}>  $entries
     */
    private function writeObject(array $entries, int $depth): string
    {
        // Sort on the UTF-16BE encoding of each name. Comparing those bytes is
        // the same as comparing UTF-16 code units as unsigned integers, which is
        // what §3.2.3 asks for.
        $sortable = [];
        foreach ($entries as [$name, $value]) {
            $sortable[] = [$this->utf16($name), $name, $value];
        }

        usort($sortable, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $parts = [];
        foreach ($sortable as [, $name, $value]) {
            $parts[] = $this->writeString($name).':'.$this->write($value, $depth + 1);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * Property names of a stdClass, in document order.
     *
     * @return list<array{string, mixed}>
     */
    private function objectEntries(stdClass $value): array
    {
        return $this->arrayEntries(get_object_vars($value));
    }

    /**
     * Keys in insertion order, as property names.
     *
     * PHP folds a key like `"10"` into the integer `10` on its way into an
     * array, so both the properties of a stdClass and the keys of a PHP array
     * can arrive as integers. The cast back is exact: only canonical decimal
     * strings are folded, and casting such an integer to a string reproduces
     * the name it came from.
     *
     * @param  array<array-key, mixed>  $value
     * @return list<array{string, mixed}>
     */
    private function arrayEntries(array $value): array
    {
        $entries = [];

        /** @var mixed $element */
        foreach ($value as $name => $element) {
            $entries[] = [(string) $name, $element];
        }

        return $entries;
    }

    /**
     * RFC 8785 §3.2.2.2 — escape only what must be escaped, in lowercase hex.
     */
    private function writeString(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new CanonicalizationException('String data is not valid UTF-8');
        }

        $out = '"';
        $offset = 0;
        $length = strlen($value);

        while ($offset < $length) {
            $run = strcspn($value, self::MUST_ESCAPE, $offset);
            if ($run > 0) {
                $out .= substr($value, $offset, $run);
                $offset += $run;

                continue;
            }

            $char = $value[$offset++];
            $out .= match ($char) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\b',
                "\x09" => '\t',
                "\x0A" => '\n',
                "\x0C" => '\f',
                "\x0D" => '\r',
                default => sprintf('\u%04x', ord($char)),
            };
        }

        return $out.'"';
    }

    private function utf16(string $name): string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            throw new CanonicalizationException('A property name is not valid UTF-8');
        }

        return mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
    }
}
