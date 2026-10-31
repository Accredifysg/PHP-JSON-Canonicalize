# RFC 8785 Conformance Harness

This directory hosts the harness that runs the
[cyberphone/json-canonicalization](https://github.com/cyberphone/json-canonicalization)
test suite against this package. The suite itself is pulled in as a git
submodule at `tests/json-canonicalization/` — these files are the harness that
drives it.

> RFC 8785 is IETF, not W3C, so there is no official test suite and no EARL
> report route. The cyberphone repository is the de facto conformance suite:
> it is the one the RFC's own author maintains, and it is what the other
> language implementations test against. The score below is self-reported,
> reproducible by running `composer test:conformance`.

## Layout

```
tests/
├── json-canonicalization/          # submodule: cyberphone/json-canonicalization
│   └── testdata/
│       ├── input/*.json            # non-canonical input
│       ├── output/*.json           # expected canonical output (UTF-8)
│       ├── outhex/*.txt            # the same bytes, in hexadecimal
│       └── numgen.js               # the ES6 number sequence generator
└── Conformance/                    # our harness (this directory)
    ├── Harness.php                 # pairs input/ with output/ and outhex/
    ├── Vector.php                  # value object per vector
    ├── NumberSequence.php          # numgen.js, ported to PHP
    ├── README.md
    └── Vectors/
        ├── VectorsTest.php         # runs JCS against each vector
        └── Es6NumbersTest.php      # checksums the generated number set
```

## The two halves

**Document vectors.** Six files, each exercising a different part of §3.2:
`arrays` (numeric property names), `french` (locale-independent sorting),
`structures` (nesting and empty objects), `unicode` (no normalization),
`values` (number formatting and string escaping), `weird` (UTF-16 sort order,
including a surrogate pair that must sort below U+FB33). Each is checked three
ways — against `output/` as text, against `outhex/` as bytes, and for
idempotence.

**ES6 numbers.** The suite's exhaustive number check is a 4 GB file of 100
million `hex-ieee,expected` lines, hosted as a release asset. It is not
downloaded here. The suite publishes the deterministic algorithm that produces
it together with the SHA-256 of each prefix, so `NumberSequence` regenerates
the sequence locally and compares checksums instead. Because the `expected`
column is produced by *this* package's formatter, a matching checksum means
every line agreed.

## Running

```bash
git submodule update --init --recursive   # once

composer test              # unit tests (the default gate)
composer test:conformance  # this suite (also gates CI)
composer test:all          # both
```

The number check runs up to a million lines by default, about 11 seconds. Raise
the ceiling to reach the larger published prefixes:

```bash
JCS_NUMBER_LINES=10000000 composer test:conformance    # ~2 minutes
JCS_NUMBER_LINES=100000000 composer test:conformance   # ~20 minutes
```

## Beyond the fixed vectors

Six documents cannot cover a specification this detailed.
[`tools/differential.js`](../../tools/differential.js) generates pseudo-random
documents from fragments chosen to collide with the rules — property names that
sort differently in UTF-16 than in UTF-8, names PHP folds to integers, every
escape class, the code points either side of the escaped range — canonicalizes
them with the suite's own JavaScript implementation, and replays them here:

```bash
node tools/differential.js 5000 | php tools/differential.php
# 5,000 cases, 0 disagreements
```

The seed is an argument, so a failing run is replayable. This is what surfaced
the one bug the fixed vectors missed: a property name beginning with a NUL byte
is legal JSON, but PHP refuses it as a dynamic property, so parsed objects
cannot be `stdClass` — hence `JsonObject`.

## Score (v1.0.0) — full conformance

| Check                              | Cases  | Passing |
| ---------------------------------- | -----: | ------: |
| Document vectors (`output/`)       |      6 |       6 |
| Document vectors (`outhex/` bytes) |      6 |       6 |
| Idempotence                        |      6 |       6 |
| ES6 number checksums               |      4 |       4 |
| **Total (default run)**            | **22** |  **22** |

The suite **gates CI** — every case passes, no allowlist.

The four number checksums run by default cover the first 1 000, 10 000,
100 000 and 1 000 000 values of the sequence. The two larger published
prefixes pass as well — all six checksums in the suite's table are reproduced,
through the full 100 000 000 values. They sit behind `JCS_NUMBER_LINES`
because of their runtime (about two minutes and nineteen minutes
respectively), not their result.

The canonical output is frozen under SemVer: it is what Data Integrity's
`ecdsa-jcs-2019` and `eddsa-jcs-2022` cryptosuites sign over, so changing it
would invalidate every signature already issued.
