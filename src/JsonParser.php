<?php

namespace Accredify\JsonCanonicalize;

use Accredify\JsonCanonicalize\Exceptions\CanonicalizationException;

/**
 * A strict RFC 8259 / I-JSON parser, written for canonicalization.
 *
 * PHP's own `json_decode` is not usable here. Decoded to arrays it folds the
 * property names `"1"` and `"10"` into integer keys and loses the difference
 * between `[]` and `{}`; decoded to objects it silently keeps the last of a set
 * of duplicate property names, which RFC 8785 §3.1 forbids. It also keeps
 * integers as PHP integers, where JCS requires every number to be an IEEE-754
 * double.
 *
 * So this parser produces exactly the shapes the serializer needs:
 *
 *   object  -> JsonObject (property names kept verbatim, duplicates rejected)
 *   array   -> list<mixed>
 *   string  -> string     (UTF-8, escapes resolved, surrogate pairs combined)
 *   number  -> float      (always; RFC 8785 §3.1)
 *   literal -> true | false | null
 *
 * @see https://www.rfc-editor.org/rfc/rfc8785#section-3.1
 */
final class JsonParser
{
    /**
     * Matches PHP's own `json_decode` default, and bounds recursion so a
     * pathologically nested document fails with an exception instead of
     * exhausting the stack.
     */
    public const DEFAULT_MAX_DEPTH = 512;

    /**
     * Characters that end a verbatim run inside a string literal: the quote, the
     * escape character, and every C0 control character (which RFC 8259 forbids
     * unescaped).
     */
    private const STRING_BREAK = "\"\\\0\1\2\3\4\5\6\7\10\11\12\13\14\15\16\17\20\21\22\23\24\25\26\27\30\31\32\33\34\35\36\37";

    private const WHITESPACE = " \t\n\r";

    private string $json = '';

    private int $length = 0;

    private int $offset = 0;

    public function __construct(
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) {}

    /**
     * Parse a UTF-8 JSON document.
     *
     * @return JsonObject|list<mixed>|string|float|bool|null
     *
     * @throws CanonicalizationException on anything that is not strictly valid I-JSON
     */
    public function parse(string $json): mixed
    {
        if (! mb_check_encoding($json, 'UTF-8')) {
            throw new CanonicalizationException('Input is not valid UTF-8');
        }

        $this->json = $json;
        $this->length = strlen($json);
        $this->offset = 0;

        $value = $this->parseValue(1);

        $this->skipWhitespace();
        if ($this->offset !== $this->length) {
            throw $this->error('Unexpected trailing data');
        }

        return $value;
    }

    /**
     * @return JsonObject|list<mixed>|string|float|bool|null
     */
    private function parseValue(int $depth): mixed
    {
        if ($depth > $this->maxDepth) {
            throw $this->error(sprintf('Maximum nesting depth of %d exceeded', $this->maxDepth));
        }

        $this->skipWhitespace();

        return match ($this->peek()) {
            '{' => $this->parseObject($depth),
            '[' => $this->parseArray($depth),
            '"' => $this->parseString(),
            't' => $this->parseKeyword('true', true),
            'f' => $this->parseKeyword('false', false),
            'n' => $this->parseKeyword('null', null),
            default => $this->parseNumber(),
        };
    }

    private function parseObject(int $depth): JsonObject
    {
        $this->offset++;

        $object = new JsonObject;

        $this->skipWhitespace();
        if ($this->peek() === '}') {
            $this->offset++;

            return $object;
        }

        while (true) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                throw $this->error('Expected a property name');
            }

            $name = $this->parseString();
            if ($object->has($name)) {
                // RFC 8785 §3.1: duplicates make the canonical form ambiguous.
                throw $this->error(sprintf('Duplicate property name "%s"', $name));
            }

            $this->skipWhitespace();
            if ($this->peek() !== ':') {
                throw $this->error('Expected ":" after a property name');
            }
            $this->offset++;

            $object->set($name, $this->parseValue($depth + 1));

            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->offset++;

                continue;
            }

            if ($next === '}') {
                $this->offset++;

                return $object;
            }

            throw $this->error('Expected "," or "}"');
        }
    }

    /**
     * @return list<mixed>
     */
    private function parseArray(int $depth): array
    {
        $this->offset++;

        $elements = [];

        $this->skipWhitespace();
        if ($this->peek() === ']') {
            $this->offset++;

            return $elements;
        }

        while (true) {
            $elements[] = $this->parseValue($depth + 1);

            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->offset++;

                continue;
            }

            if ($next === ']') {
                $this->offset++;

                return $elements;
            }

            throw $this->error('Expected "," or "]"');
        }
    }

    private function parseString(): string
    {
        $this->offset++;

        $value = '';

        while (true) {
            $run = strcspn($this->json, self::STRING_BREAK, $this->offset);
            if ($run > 0) {
                $value .= substr($this->json, $this->offset, $run);
                $this->offset += $run;
            }

            if ($this->offset >= $this->length) {
                throw $this->error('Unterminated string');
            }

            $char = $this->json[$this->offset];

            if ($char === '"') {
                $this->offset++;

                return $value;
            }

            if ($char === '\\') {
                $value .= $this->parseEscape();

                continue;
            }

            throw $this->error(sprintf('Unescaped control character U+%04X in string', ord($char)));
        }
    }

    private function parseEscape(): string
    {
        $this->offset++;

        if ($this->offset >= $this->length) {
            throw $this->error('Unterminated escape sequence');
        }

        $char = $this->json[$this->offset++];

        return match ($char) {
            '"' => '"',
            '\\' => '\\',
            '/' => '/',
            'b' => "\x08",
            'f' => "\x0C",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'u' => $this->parseUnicodeEscape(),
            default => throw $this->error(sprintf('Invalid escape sequence "\\%s"', $char)),
        };
    }

    /**
     * Resolve a `\uXXXX` escape, combining a surrogate pair into one code point.
     *
     * RFC 8785 §3.2.2.2 requires terminating on lone surrogates: they cannot be
     * encoded as UTF-8, so leaving them in place would break signatures.
     */
    private function parseUnicodeEscape(): string
    {
        $unit = $this->readHex4();

        if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
            throw $this->error(sprintf('Lone low surrogate \\u%04X in string', $unit));
        }

        if ($unit >= 0xD800 && $unit <= 0xDBFF) {
            if (substr($this->json, $this->offset, 2) !== '\\u') {
                throw $this->error(sprintf('Lone high surrogate \\u%04X in string', $unit));
            }
            $this->offset += 2;

            $low = $this->readHex4();
            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw $this->error(sprintf('High surrogate \\u%04X is not followed by a low surrogate', $unit));
            }

            $unit = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
        }

        // Always a code point: \uXXXX bounds this at U+FFFF, and a combined
        // surrogate pair at U+10FFFF.
        return mb_chr($unit, 'UTF-8');
    }

    private function readHex4(): int
    {
        $hex = substr($this->json, $this->offset, 4);

        if (strlen($hex) !== 4 || ! ctype_xdigit($hex)) {
            throw $this->error('Invalid \\u escape sequence');
        }

        $this->offset += 4;

        return (int) hexdec($hex);
    }

    /**
     * RFC 8259 number grammar. The literal is converted with PHP's correctly
     * rounded `strtod`, which is the same rounding ECMAScript applies.
     */
    private function parseNumber(): float
    {
        $start = $this->offset;

        if ($this->peek() === '-') {
            $this->offset++;
        }

        if ($this->peek() === '0') {
            $this->offset++;
        } elseif ($this->consumeDigits() === 0) {
            throw $this->error('Expected a JSON value');
        }

        if ($this->peek() === '.') {
            $this->offset++;
            if ($this->consumeDigits() === 0) {
                throw $this->error('Expected a digit after the decimal point');
            }
        }

        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $this->offset++;
            if ($this->peek() === '+' || $this->peek() === '-') {
                $this->offset++;
            }
            if ($this->consumeDigits() === 0) {
                throw $this->error('Expected a digit in the exponent');
            }
        }

        $literal = substr($this->json, $start, $this->offset - $start);
        $value = (float) $literal;

        if (is_infinite($value)) {
            // RFC 8785 §3.1: numbers must be expressible as IEEE-754 doubles.
            throw $this->error(sprintf('Number %s overflows IEEE-754 double precision', $literal));
        }

        return $value;
    }

    private function parseKeyword(string $keyword, ?bool $value): ?bool
    {
        if (substr($this->json, $this->offset, strlen($keyword)) !== $keyword) {
            throw $this->error('Expected a JSON value');
        }

        $this->offset += strlen($keyword);

        return $value;
    }

    private function consumeDigits(): int
    {
        $run = strspn($this->json, '0123456789', $this->offset);
        $this->offset += $run;

        return $run;
    }

    private function skipWhitespace(): void
    {
        $this->offset += strspn($this->json, self::WHITESPACE, $this->offset);
    }

    private function peek(): ?string
    {
        return $this->offset < $this->length ? $this->json[$this->offset] : null;
    }

    private function error(string $message): CanonicalizationException
    {
        return CanonicalizationException::atOffset($message, $this->offset);
    }
}
