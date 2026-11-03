#!/usr/bin/env bash
#
# Assembles the RFC 8785 conformance report into a static site for GitHub
# Pages, published at jcs.ietf.reports.conformance.accredify.dev.
#
# Three files, each for a different reader:
#
#   *.json       the conformance report itself — every case, the suite
#                revision it ran against, and who is vouching for it (we are).
#                This is the artefact; the rest is presentation.
#   index.json   a summary the conformance index at conformance.accredify.dev
#                reads on every build. Plain JSON over public HTTPS, no
#                credentials — the index links rather than aggregates, so this
#                is how it learns the numbers without holding a token.
#   index.html   for a person who followed a link here.
#
# Why this site exists at all, when the sibling libraries publish theirs to
# support a W3C submission: RFC 8785 has no submission to support. IETF
# publishes no suite and keeps no implementation registry, so a page that says
# plainly what was run, against which revision, and that nobody else checked
# it, is the whole of what can honestly be offered. Saying less would be
# modest; saying more would be a claim no body backs.
#
# Expects the report to exist already; generating it needs the suite submodule
# and a full test run, which is the workflow's job.
set -euo pipefail

out=site
report="${1:?usage: build-report-site.sh <report.json> <version>}"
version="${2:?usage: build-report-site.sh <report.json> <version>}"

[ -f "$report" ] || { echo "no such report: $report" >&2; exit 1; }

command -v jq >/dev/null || { echo "jq is required" >&2; exit 1; }

mkdir -p "$out"
cp "$report" "$out/"
basename=$(basename "$report")

# Must match the Route 53 record in Conformance-Infra
# (conformance_pages_dns.tf) and the domain under Settings -> Pages.
#
# Under ietf. rather than the w3c. its siblings use: that segment names the
# body the specification comes from, and RFC 8785 is not a W3C document.
#
# Note this file does NOT claim the domain on an Actions-based deployment —
# that comes from the repository setting. It is kept because it documents
# which name the site expects, and because a mismatch between the two is worth
# being able to see in a diff.
echo "jcs.ietf.reports.conformance.accredify.dev" > "$out/CNAME"

# Counted from the report rather than from the test run, deliberately. The
# report is the published artefact, so what it asserts is the only number
# worth republishing — a suite that passed while the report under-claims
# coverage is still a report that under-claims coverage.
read -r passed failed total < <(
  jq -r '[.summary.passed, .summary.failed, .summary.total] | @tsv' "$out/$basename"
)

[ "${total:-0}" -gt 0 ] || { echo "report asserts nothing — refusing to publish" >&2; exit 1; }

suite_revision=$(jq -r '.suite.revision // "unknown"' "$out/$basename")
suite_short=${suite_revision:0:12}

generated=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
shown=$(date -u '+%Y-%m-%d %H:%M UTC')

# `format: summary` rather than the index's default (a W3C test-runner
# document) or `earl-summary`. The shape is the same handful of totals as an
# EARL summary, but calling it EARL would be false — there are no earl:
# assertions here and no report filed anywhere — and the index renders the two
# differently: an EARL report's failures are documented limitations, where a
# failure here is just a failure.
cat > "$out/index.json" <<JSON
{
  "format": "summary",
  "attribution": "self-reported",
  "implementation": "PHP-JSON-Canonicalize",
  "version": "$version",
  "suite": "cyberphone/json-canonicalization (RFC 8785)",
  "suiteRevision": "$suite_revision",
  "report": "$basename",
  "generated": "$generated",
  "passed": $passed,
  "failed": $failed,
  "total": $total
}
JSON

if [ "$failed" -gt 0 ]; then
  banner="<p class=\"banner bad\">$failed of $total cases failing.</p>"
else
  banner="<p class=\"banner good\">All $total cases passing.</p>"
fi

cat > "$out/index.html" <<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RFC 8785 JSON Canonicalization — PHP-JSON-Canonicalize</title>
<style>
  :root { color-scheme: light; --bg:#f4f6f8; --card:#fff; --fg:#111820; --muted:#5a6a78;
          --rule:#dde4ea; --good:#176b48; --bad:#9d2f1f; --accent:#0b6b74;
          --good-bg:#e4f1ea; --bad-bg:#faeae6; --warn:#8a5a00; --warn-bg:#fbf1dd; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#0d1318; --card:#161e25; --fg:#e4eaf0; --muted:#8fa0af;
            --rule:#25323d; --good:#4fc08d; --bad:#f08a74; --accent:#4cc2cc;
            --good-bg:#11291e; --bad-bg:#2b1612; --warn:#d8a33f; --warn-bg:#2a2110; }
  }
  *,*::before,*::after { box-sizing:border-box; }
  body { margin:0; padding:56px 24px 96px; background:var(--bg); color:var(--fg);
         font:15.5px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  main { max-width:760px; margin:0 auto; }
  h1 { font-size:1.9rem; letter-spacing:-0.02em; margin:0 0 8px; }
  .sub { color:var(--muted); margin:0 0 24px; }
  .banner { padding:12px 16px; border-radius:6px; font-weight:600; margin:0 0 16px; }
  .good { background:var(--good-bg); color:var(--good); }
  .bad { background:var(--bad-bg); color:var(--bad); }
  .selfreported { padding:12px 16px; border-radius:6px; margin:0 0 24px;
                  background:var(--warn-bg); color:var(--warn); font-size:.92rem; }
  .selfreported strong { color:var(--warn); }
  dl { display:grid; grid-template-columns:auto 1fr; gap:10px 20px; margin:0 0 28px;
       background:var(--card); border:1px solid var(--rule); border-radius:8px; padding:18px 20px; }
  dt { color:var(--muted); font-size:.85rem; }
  dd { margin:0; font-variant-numeric:tabular-nums; overflow-wrap:anywhere; }
  code { font-size:.92em; }
  p { max-width:68ch; }
  footer { margin-top:32px; color:var(--muted); font-size:.88rem;
           border-top:1px solid var(--rule); padding-top:18px; }
  a { color:var(--accent); }
</style>
</head>
<body>
<main>
  <h1>RFC 8785 JSON Canonicalization</h1>
  <p class="sub">Conformance report for
     <a href="https://github.com/Accredifysg/PHP-JSON-Canonicalize">PHP-JSON-Canonicalize</a>
     $version, against the
     <a href="https://github.com/cyberphone/json-canonicalization">cyberphone/json-canonicalization</a>
     test vectors.</p>
  $banner
  <p class="selfreported"><strong>Self-reported.</strong>
     <a href="https://www.rfc-editor.org/rfc/rfc8785">RFC 8785</a> is an IETF
     specification. It has no official test suite, no implementation registry
     and no EARL submission route, so no standards body publishes or reviews
     this result — unlike our
     <a href="https://rdf-canon.w3c.reports.conformance.accredify.dev/">RDF canonicalization</a>
     and <a href="https://json-ld.w3c.reports.conformance.accredify.dev/">JSON-LD</a>
     reports, which are filed with W3C. What makes this one checkable is that
     it is reproducible: <code>composer test:conformance</code>.</p>
  <dl>
    <dt>Cases</dt><dd>$total</dd>
    <dt>Passed</dt><dd>$passed</dd>
    <dt>Failed</dt><dd>$failed</dd>
    <dt>Suite revision</dt><dd><code>$suite_short</code></dd>
    <dt>Report</dt><dd><a href="./$basename">$basename</a> (JSON)</dd>
    <dt>Generated</dt><dd>$shown</dd>
  </dl>
  <p>The suite is the one the specification's author maintains, and the one the
     other language implementations test against. It is pinned here as a git
     submodule, so the revision above says exactly which vectors ran — "passes
     the vectors" means nothing without it.</p>
  <p>The generator cross-checks the test log against the vectors found in that
     submodule, in both directions, and fails rather than emitting a report
     that silently under- or over-claims coverage. An empty submodule makes
     every case vanish and the suite exit clean; that is the failure this
     check exists to catch.</p>
  <footer>
    <p><strong>Nobody else has checked this.</strong> Where a standards body
       publishes a result, that page is the record. For RFC 8785 there is no
       such page and no such body, so this is evidence rather than a record,
       and is not a substitute for one.</p>
    <p>Part of <a href="https://conformance.accredify.dev">conformance.accredify.dev</a>,
       which indexes every conformance target we are working through.</p>
  </footer>
</main>
</body>
</html>
HTML

echo "Built $out/ — $passed/$total passing, version $version, suite $suite_short"
