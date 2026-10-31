//
// Differential check: this package against the suite's JavaScript reference.
//
// The fixed vectors cover the cases the RFC calls out; this covers the ones
// nobody thought to write down. It generates pseudo-random documents, runs them
// through cyberphone/json-canonicalization's own ECMAScript canonicalizer, and
// emits one JSON line per case for tools/differential.php to replay.
//
//   node tools/differential.js [cases] [seed] | php tools/differential.php
//
// It is what found the NUL-leading property name a stdClass could not hold.
//
const path = require('path')

const reference = path.join(__dirname, '..', 'tests', 'json-canonicalization', 'node-es6', 'canonicalize.js')
let canonicalize
try {
  canonicalize = require(reference)
} catch {
  console.error('Reference canonicalizer not found. Run `git submodule update --init --recursive`.')
  process.exit(1)
}

const cases = Number(process.argv[2]) || 5000
let seed = (Number(process.argv[3]) || 0x2545f491) >>> 0

// xorshift32 — deterministic, so a failing run is replayable from its seed.
function rnd() {
  seed ^= seed << 13
  seed >>>= 0
  seed ^= seed >> 17
  seed ^= seed << 5
  seed >>>= 0
  return seed / 0x100000000
}
const pick = (a) => a[Math.floor(rnd() * a.length)]

// Fragments chosen to collide with the rules: names that sort differently in
// UTF-16 than in UTF-8, names PHP folds to integers, every escape class, and
// the boundary code points either side of the escaped range.
const FRAGMENTS = [
  'a', 'Z', '1', '10', '', '\n', '\r', '\t', '\b', '\f', ' ', 'key',
  '\u0000', '\u001f', '\u007f', '\u0080', '\u00f6', '\u20ac', '\u{1f602}', '\ufb33',
  '</script>', '\\', '"', '/',
]

function str() {
  let s = ''
  for (let i = 0, n = Math.floor(rnd() * 4); i <= n; i++) s += pick(FRAGMENTS)
  return s
}

function num() {
  switch (Math.floor(rnd() * 5)) {
    case 0: return Math.floor(rnd() * 2e9) - 1e9
    case 1: return (rnd() - 0.5) * Math.pow(10, Math.floor(rnd() * 60) - 30)
    case 2: return pick([0, -0, 1e21, 1e-6, 5e-7, 9007199254740992, 1.7976931348623157e308, 5e-324])
    case 3: return Math.round(rnd() * 1e6) / Math.pow(10, Math.floor(rnd() * 8))
    default: return rnd() * Math.pow(10, Math.floor(rnd() * 600) - 300)
  }
}

function value(depth) {
  switch (Math.floor(rnd() * (depth > 4 ? 5 : 8))) {
    case 0: return null
    case 1: return true
    case 2: return false
    case 3: return num()
    case 4: return str()
    case 5: {
      const a = []
      for (let i = 0, n = Math.floor(rnd() * 4); i < n; i++) a.push(value(depth + 1))
      return a
    }
    default: {
      const o = {}
      for (let i = 0, n = Math.floor(rnd() * 5); i < n; i++) o[str()] = value(depth + 1)
      return o
    }
  }
}

const lines = []
for (let i = 0; i < cases; i++) {
  const v = value(0)
  lines.push(JSON.stringify({ in: JSON.stringify(v), out: canonicalize(v) }))
}
process.stdout.write(lines.join('\n') + '\n')
