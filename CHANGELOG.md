# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package
adheres to [Semantic Versioning](https://semver.org/).

> **The canonical output is part of the public API.** Signatures are computed
> over these bytes, so a change to them is a major version bump — never a patch.

## [1.0.0] — 2026-10-31

Initial release: RFC 8785 (JCS) canonicalization, conformant with the cyberphone
test vectors.

### Added

- `JCS` — the entry point. `canonicalize(string $json)` for JSON text,
  `canonicalizeValue(mixed $value)` for a document already built in PHP
  (arrays, `stdClass`, `JsonSerializable`, scalars).
- `Es6NumberFormatter` — ECMAScript `Number::toString` (ECMA-262 §7.1.12.1,
  "Note 2" included). Finds the shortest round-tripping digit string, then lays
  it out with the fixed/exponential boundaries ECMAScript uses rather than the
  ones PHP uses.
- `JsonParser` — a strict RFC 8259 / I-JSON parser. It exists because
  `json_decode` cannot be used here: decoded to arrays it folds the property
  names `"1"` and `"10"` into integer keys and loses `[]` vs `{}`; decoded to
  objects it silently keeps the last of a set of duplicate names, which
  RFC 8785 §3.1 forbids; and it keeps integers as PHP integers where JCS needs
  IEEE-754 doubles.
- `JsonObject` — what the parser produces for a JSON object: property names in
  document order, mapped to values. `stdClass` cannot stand in for it, because
  PHP refuses a dynamic property whose name begins with a NUL byte and
  `{"\u0000a":1}` is legal JSON that other implementations canonicalize
  without comment.
- `Serializer` — the writer: UTF-16 code unit property ordering, minimal
  string escaping, ECMAScript numbers, no whitespace. Accepts `JsonObject`,
  arrays, `stdClass`, `JsonSerializable` and scalars.
- `Contracts\Canonicalizer` and `Exceptions\CanonicalizationException`.
- Conformance harness over the cyberphone suite (a submodule at
  `tests/json-canonicalization/`): six document vectors checked against both
  `output/` and `outhex/`, plus the ES6 number set regenerated locally and
  checked by SHA-256 against the published checksums. All six published
  checksums are reproduced, through the full 100 000 000 values; the default
  run stops at a million for the sake of its runtime.
- `tools/differential.js` and `tools/differential.php` — a differential check
  against the suite's own JavaScript canonicalizer over pseudo-random
  documents, which is how the NUL-leading property name above was found.

### Notes on behaviour

- **Fails closed.** Duplicate property names, lone surrogates, NaN, Infinity,
  numbers that overflow double precision, invalid UTF-8, unescaped control
  characters and trailing data all raise `CanonicalizationException`.
- **Silent where ECMAScript is silent.** Integers beyond 2^53 round to the
  nearest double and values below the smallest subnormal underflow to zero,
  because that is what a JCS implementation built on ECMAScript does.
- **No Unicode normalization**, per RFC 8785 §3.1 — string data is preserved
  exactly as written.
- Nesting is bounded at 512 levels by default, matching `json_decode`.

[1.0.0]: https://github.com/accredifysg/php-json-canonicalize/releases/tag/v1.0.0
