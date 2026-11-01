<?php

namespace Accredify\JsonCanonicalize;

use Countable;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * A parsed JSON object: property names in document order, mapped to values.
 *
 * `stdClass` cannot stand in for this. PHP refuses a dynamic property whose
 * name begins with a NUL byte — `Cannot access property starting with "\0"` —
 * so `{"\u0000a":1}`, which is perfectly legal JSON and which other JCS
 * implementations canonicalize without comment, cannot be held as an object at
 * all. A PHP array has no such restriction, so the members live in one of
 * those, behind an interface that hands property names back as strings.
 *
 * @implements IteratorAggregate<string, mixed>
 */
final class JsonObject implements Countable, IteratorAggregate
{
    /**
     * PHP folds a key like `"10"` into the integer `10` on its way in. The cast
     * back is exact — only canonical decimal strings are folded, and casting
     * such an integer to a string reproduces the name it came from.
     *
     * @var array<array-key, mixed>
     */
    private array $members = [];

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->members);
    }

    public function get(string $name): mixed
    {
        return $this->members[$name] ?? null;
    }

    public function set(string $name, mixed $value): void
    {
        $this->members[$name] = $value;
    }

    /**
     * The members as a PHP array, keys unconverted.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->members;
    }

    public function count(): int
    {
        return count($this->members);
    }

    /**
     * @return Generator<string, mixed>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->members as $name => $value) {
            yield (string) $name => $value;
        }
    }
}
