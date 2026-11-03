# PHP-JSON-Canonicalize

[![CI](https://img.shields.io/github/actions/workflow/status/accredifysg/php-json-canonicalize/main.yml?branch=main)](https://github.com/Accredifysg/PHP-JSON-Canonicalize/actions/workflows/main.yml)
![Test coverage](coverage/badge.svg)
![Packagist Version](https://img.shields.io/packagist/v/accredifysg/php-json-canonicalize)
![PHP Version](https://img.shields.io/packagist/php-v/accredifysg/php-json-canonicalize)
[![License](https://img.shields.io/github/license/Accredifysg/PHP-JSON-Canonicalize)](LICENSE)
![JCS conformance](https://img.shields.io/badge/RFC%208785-conformant-brightgreen)

A PHP implementation of the IETF
[JSON Canonicalization Scheme (JCS, RFC 8785)](https://www.rfc-editor.org/rfc/rfc8785).

> **Status: stable (1.0).** Conformant with the
> [cyberphone test vectors](https://github.com/cyberphone/json-canonicalization)
> — the de facto RFC 8785 suite — including the generated ES6 number set. The
> public API **and** the canonical output are covered by
> [Semantic Versioning](https://semver.org/); the output is consensus-critical,
> so changing it is a major version bump.

## What it does

JCS turns any JSON document into the one byte sequence every conforming
implementation agrees on, so the result can be hashed and signed:

```
JSON text  ──►  parse  ──►  sort, strip, re-serialize  ──►  canonical UTF-8
```

Four rules, from RFC 8785 §3.2:

- **No whitespace** between tokens.
- **Property names sorted** as arrays of UTF-16 code units, recursively.
  Array element order is never changed.
- **Minimal string escaping** — `"` and `\`, the five named control escapes,
  and `\uhhhh` in lowercase for the rest of U+0000–U+001F. Nothing else.
- **ECMAScript number formatting** — `Number::toString`, not PHP's.

## Installation

```bash
composer require accredifysg/php-json-canonicalize:^1.0
```

Requires **PHP 8.2+** with the `hash` and `mbstring` extensions.

## Usage

```php
use Accredify\JsonCanonicalize\JCS;

echo (new JCS)->canonicalize('{"b":1,"a":[2,3.0]}');
// {"a":[2,3],"b":1}
```

`JCS` implements `Accredify\JsonCanonicalize\Contracts\Canonicalizer`. The
return value is UTF-8 bytes, ready to hash:

```php
$signature = sodium_crypto_sign_detached(
    (new JCS)->canonicalize($credential),
    $privateKey,
);
```

### Canonicalizing a value you already have

If the document was built in PHP rather than parsed, skip the `json_encode`
round-trip:

```php
(new JCS)->canonicalizeValue(['b' => 1, 'a' => [2, 3.0]]);
// {"a":[2,3],"b":1}
```

This accepts arrays, `stdClass`, `JsonSerializable` and scalars. A PHP array
serializes as a JSON array when it is a list and as a JSON object otherwise,
which leaves one ambiguity PHP cannot resolve: `[]` means an empty array, so an
empty JSON object has to be written as `new stdClass`.

### Components

The two halves are separately usable and separately tested:

- `JsonParser` — strict RFC 8259 / I-JSON text → `JsonObject` | `list` |
  `string` | `float` | `bool` | `null`
- `JsonObject` — a parsed object: property names in document order, mapped to
  values. It exists because PHP refuses a dynamic property whose name begins
  with a NUL byte, so a `stdClass` cannot hold every legal JSON object.
- `Serializer` — any of the above → canonical UTF-8
- `Es6NumberFormatter` — one double → its ECMAScript `String()` form

Both `JsonParser` and `Serializer` take a `maxDepth` (default 512) and are
accepted by `JCS`'s constructor.

## Fails closed

Everything that could make two implementations disagree raises
`CanonicalizationException` rather than producing best-effort output:

| Input                                  | Why it is refused   |
| -------------------------------------- | ------------------- |
| `{"a":1,"a":2}`                        | RFC 8785 §3.1       |
| `"\ud83d"` (lone surrogate)            | RFC 8785 §3.2.2.2   |
| `NaN`, `Infinity`, `1e400`             | RFC 8785 §3.2.2.3   |
| Invalid UTF-8, unescaped control bytes | RFC 8259            |
| Trailing data, trailing commas         | RFC 8259            |

Two conversions are silent, because ECMAScript does them silently too: integers
beyond 2^53 round to the nearest double (`9007199254740993` → `9007199254740992`),
and values below the smallest subnormal underflow to `0`.

## The only hard part is numbers

Everything in JCS is mechanical except §3.2.2.3, which defers to ECMAScript's
`Number::toString`. PHP computes the shortest round-tripping digits already —
`serialize_precision = -1` has done that since 7.1 — but lays them out
differently:

| Value                | `json_encode` | RFC 8785 requires |
| -------------------- | ------------- | ----------------- |
| `-0.0`               | `-0`          | `0`               |
| `1e-6`               | `1.0e-6`      | `0.000001`        |
| `5e-7`               | `5.0e-7`      | `5e-7`            |
| `1e21`               | `1.0e+21`     | `1e+21`           |
| `0.3333333333333333` | unchanged     | unchanged         |

The rule is a pair of boundaries: fixed notation for `1e-6 ≤ |x| < 1e21`,
exponential outside it. PHP goes exponential well before both.
`Es6NumberFormatter` implements the layout directly, and is checked against
RFC 8785 Appendix B and against all 100 million values of the suite's
generated sequence.

## Conformance

Tested against the
[cyberphone/json-canonicalization](https://github.com/cyberphone/json-canonicalization)
suite, pulled in as a git submodule at `tests/json-canonicalization/`. See
[`tests/Conformance/README.md`](tests/Conformance/README.md) for the harness
layout.

```bash
git submodule update --init --recursive   # once

composer test              # unit tests (the default gate)
composer test:conformance  # conformance suite (gates CI)
composer test:all          # both
```

### Score (v1.0.0) — full conformance

| Check                              | Cases  | Passing |
| ---------------------------------- | -----: | ------: |
| Document vectors (`output/`)       |      6 |       6 |
| Document vectors (`outhex/` bytes) |      6 |       6 |
| Idempotence                        |      6 |       6 |
| ES6 number checksums               |      4 |       4 |
| **Total (default run)**            | **22** |  **22** |

RFC 8785 is IETF, not W3C: there is no official test suite and no EARL report
to file, so this is a self-reported score, reproducible with one command.

The same score is published, per case and pinned to the suite revision it ran
against, at
[jcs.ietf.reports.conformance.accredify.dev](https://jcs.ietf.reports.conformance.accredify.dev)
— rebuilt on every release and weekly, and indexed from
[conformance.accredify.dev](https://conformance.accredify.dev) alongside the
reports we do file with W3C. It says "self-reported" on its face, because
nobody else checks it.

Beyond the fixed vectors, [`tools/differential.js`](tools/differential.js)
generates pseudo-random documents, canonicalizes them with the suite's own
JavaScript implementation, and replays them through this one:

```bash
node tools/differential.js 5000 | php tools/differential.php
# 5,000 cases, 0 disagreements
```

That is what surfaced the NUL-leading property name the fixed vectors never
exercise.

The ES6 number check is the substantive one. Rather than download the suite's
4 GB file of 100 million values, the harness regenerates the sequence from the
published algorithm and compares SHA-256 against the published checksums. The
default run proves the first million values agree exactly; `JCS_NUMBER_LINES`
raises that to the larger published prefixes, and all six checksums in the
suite's table are reproduced — the full 100 million values, in about nineteen
minutes.

## Scope

- [x] RFC 8785 canonicalization over JSON text and over native PHP values.
- [x] ECMAScript `Number::toString`, including the fixed/exponential boundaries
      and round-to-even ties.
- [x] UTF-16 code unit property ordering, including surrogate pairs.
- [x] I-JSON input validation (duplicates, lone surrogates, non-finite numbers).

Out of scope: JSON Web Signature wrapping (see RFC 8785 Appendix F), and the
`extended-json` number-as-string convention of Appendix D.

## License

[MIT](LICENSE) © Accredify
