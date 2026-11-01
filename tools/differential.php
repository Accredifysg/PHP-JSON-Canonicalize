<?php

declare(strict_types=1);

/*
 * Replays the cases tools/differential.js emits, comparing this package's
 * output against the JavaScript reference byte for byte, and checking that
 * every reference output is also a fixed point.
 *
 *   node tools/differential.js [cases] [seed] | php tools/differential.php
 *
 * Exits non-zero on disagreement, after printing up to five.
 */

require __DIR__.'/../vendor/autoload.php';

use Accredify\JsonCanonicalize\JCS;

$jcs = new JCS;
$cases = 0;
$failures = 0;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    /** @var array{in: string, out: string} $case */
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $cases++;

    foreach (['canonical' => $case['in'], 'idempotent' => $case['out']] as $label => $input) {
        try {
            $actual = $jcs->canonicalize($input);
        } catch (Throwable $e) {
            $actual = 'threw: '.$e->getMessage();
        }

        if ($actual === $case['out']) {
            continue;
        }

        $failures++;
        if ($failures <= 5) {
            fwrite(STDERR, sprintf(
                "%s mismatch\n  input : %s\n  js    : %s\n  php   : %s\n\n",
                $label,
                $input,
                $case['out'],
                $actual,
            ));
        }
    }
}

printf("%s cases, %s disagreements\n", number_format($cases), number_format($failures));

exit($failures === 0 ? 0 : 1);
