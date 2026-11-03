<?php

declare(strict_types=1);

/**
 * Turns a JUnit log from the Conformance test suite into the published
 * conformance report.
 *
 * RFC 8785 is IETF, not W3C. There is no official test suite, no registry of
 * implementations and no EARL report to file — so unlike PHP-RDF-Canonicalize
 * and PHP-JSON-LD, nothing here is submitted to a body. This report is the
 * artefact instead: a per-case record of what ran against which revision of
 * the de facto suite, in a shape a reader (or the conformance index) can check
 * rather than take on trust.
 *
 * It therefore says "self-reported" in the document itself. A report nobody
 * audits that does not say so is the failure mode worth designing against.
 *
 * Usage:
 *   php scripts/generate-conformance-report.php <junit.xml> <version>
 *
 * Writes JSON to stdout; diagnostics to stderr; exits non-zero rather than
 * emitting a report it cannot vouch for.
 */

use Accredify\JsonCanonicalize\Tests\Conformance\Harness;
use Accredify\JsonCanonicalize\Tests\Conformance\NumberSequence;

require __DIR__.'/../vendor/autoload.php';

const SUITE_URL = 'https://github.com/cyberphone/json-canonicalization';
const HOMEPAGE = 'https://github.com/Accredifysg/PHP-JSON-Canonicalize';

/**
 * The three checks VectorsTest runs over every document vector, in the order
 * the file declares them. Named here because the cross-check below needs to
 * know how many assertions one vector is supposed to produce, and inferring
 * that from the log is how a silently-dropped check goes unnoticed.
 */
const CHECKS_PER_VECTOR = 3;

function fail(string $message): never
{
    fwrite(STDERR, "generate-conformance-report: {$message}\n");
    exit(1);
}

$junitPath = $argv[1] ?? fail('usage: generate-conformance-report.php <junit.xml> <version>');
$version = $argv[2] ?? fail('usage: generate-conformance-report.php <junit.xml> <version>');

if (! is_file($junitPath)) {
    fail("no such JUnit log: {$junitPath}");
}

// ---------------------------------------------------------------------------
// What the suite says should have run.
//
// Counted from the submodule rather than from the log, so the two can be
// compared. A missing or empty submodule makes every dataset yield nothing;
// Pest then reports zero tests and exits 0, and a report generated from that
// log would claim full conformance over an empty suite. That is the one
// failure this script exists to make impossible.
// ---------------------------------------------------------------------------

try {
    $vectors = iterator_to_array(Harness::fromDefaultLocation()->vectors());
} catch (Throwable $e) {
    fail($e->getMessage());
}

$ceiling = (int) (getenv('JCS_NUMBER_LINES') ?: 1_000_000);
$prefixes = array_filter(
    NumberSequence::PUBLISHED_CHECKSUMS,
    static fn (string $checksum, int $lines): bool => $lines <= $ceiling,
    ARRAY_FILTER_USE_BOTH,
);

if ($prefixes === []) {
    fail("JCS_NUMBER_LINES={$ceiling} is below the smallest published prefix; no number checksum would run");
}

$expected = (count($vectors) * CHECKS_PER_VECTOR) + count($prefixes);

// ---------------------------------------------------------------------------
// What actually ran.
// ---------------------------------------------------------------------------

$previous = libxml_use_internal_errors(true);
$xml = simplexml_load_file($junitPath);
libxml_use_internal_errors($previous);

if ($xml === false) {
    fail("could not parse {$junitPath} as XML");
}

$assertions = [];

foreach ($xml->xpath('//testcase') ?: [] as $case) {
    $outcome = match (true) {
        isset($case->failure), isset($case->error) => 'failed',
        isset($case->skipped) => 'skipped',
        default => 'passed',
    };

    $assertion = [
        'test' => trim((string) $case['name']),
        'check' => checkNameFor((string) $case['classname']),
        'outcome' => $outcome,
    ];

    // Only on a failure, and only the first line. The whole point of keeping
    // it is that a reader can see what broke without re-running the suite; a
    // full stack trace in a published document is noise, not evidence.
    if ($outcome === 'failed') {
        $detail = trim((string) ($case->failure ?? $case->error));
        $assertion['detail'] = strtok($detail, "\n") ?: 'no detail recorded';
    }

    $assertions[] = $assertion;
}

if ($assertions === []) {
    fail('the JUnit log records no test cases — refusing to publish a report that asserts nothing');
}

// Both directions, as PHP-RDF-Canonicalize's EARL generator does against the
// W3C manifest. Under-claiming (a check that silently stopped running) and
// over-claiming (cases counted that the suite does not define) are both
// reasons to publish nothing at all.
if (count($assertions) !== $expected) {
    fail(sprintf(
        'suite defines %d cases (%d vectors x %d checks + %d number prefixes) but the log records %d — '.
        'refusing to publish a report whose coverage does not match the suite',
        $expected,
        count($vectors),
        CHECKS_PER_VECTOR,
        count($prefixes),
        count($assertions),
    ));
}

$passed = count(array_filter($assertions, static fn (array $a): bool => $a['outcome'] === 'passed'));
$failed = count(array_filter($assertions, static fn (array $a): bool => $a['outcome'] === 'failed'));
$skipped = count(array_filter($assertions, static fn (array $a): bool => $a['outcome'] === 'skipped'));

$report = [
    'report' => 'RFC 8785 JSON Canonicalization Scheme — conformance report',
    'attribution' => 'self-reported',
    'note' => 'RFC 8785 is an IETF specification. It has no official test suite, no implementation '
        .'registry and no EARL submission route, so no standards body publishes or reviews this '
        .'result. It is reproducible with `composer test:conformance`.',
    'generated' => gmdate('Y-m-d\TH:i:s\Z'),
    'specification' => 'https://www.rfc-editor.org/rfc/rfc8785',
    'implementation' => [
        'name' => 'PHP-JSON-Canonicalize',
        'version' => $version,
        'homepage' => HOMEPAGE,
        'language' => 'PHP',
    ],
    'suite' => [
        'name' => 'cyberphone/json-canonicalization',
        'url' => SUITE_URL,
        'revision' => submoduleRevision(),
        'role' => 'de facto RFC 8785 conformance suite, maintained by the specification author',
        'vectors' => array_keys($vectors),
        'numberPrefixes' => array_keys($prefixes),
    ],
    'summary' => [
        'passed' => $passed,
        'failed' => $failed,
        'skipped' => $skipped,
        'total' => count($assertions),
    ],
    'assertions' => $assertions,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

/**
 * The class a case came from, as the check a reader recognises.
 *
 * Both halves of the suite are described in tests/Conformance/README.md; these
 * are the same two names.
 */
function checkNameFor(string $classname): string
{
    return match (true) {
        str_contains($classname, 'Es6Numbers') => 'ES6 number checksums',
        str_contains($classname, 'Vectors') => 'Document vectors',
        default => 'Other',
    };
}

/**
 * The exact revision of the suite this report was produced against.
 *
 * The suite is a submodule pointing at a repository we do not control, so
 * "passes the vectors" means nothing without saying which vectors. Recorded as
 * unknown rather than fatal: a tarball checkout has no git metadata, and that
 * is a worse report rather than an invalid one.
 */
function submoduleRevision(): string
{
    $path = escapeshellarg(__DIR__.'/../tests/json-canonicalization');
    $sha = @shell_exec("git -C {$path} rev-parse HEAD 2>/dev/null");

    return is_string($sha) && trim($sha) !== '' ? trim($sha) : 'unknown';
}
