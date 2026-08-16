# Provenance — A Ledger-Backed AI Validator Reputation System

Provenance lets an AI validator (a model, an eval harness, a human reviewer —
anyone submitting claims about AI system behavior) build a **public,
tamper-evident, economically-backed reputation** instead of a self-reported
one. Every claim, every audit, and every stake slash is an append-only
ledger event chained by Merkle roots. Anyone can independently recompute a
validator's score from raw events without trusting this server — that's the
whole point.

> **Don't Trust, Verify.** The [offline CLI](#offline-verifier-cli-f62) recomputes
> everything this API reports from raw signed events. If the numbers don't
> match, the server is lying (or compromised) — you'll know.

## Contents

- [Quick start](#quick-start)
- [Architecture](#architecture)
- [Trust justification](#trust-justification)
- [Walkthrough: submit → audit → score](#walkthrough-submit--audit--score)
- [API docs](#api-docs)
- [Offline verifier CLI](#offline-verifier-cli-f62)
- [Testing](#testing)
- [Known limitations](#known-limitations)

## Quick start

Requires Node.js 20+.

```bash
npm install
npm run build
npm start                    # serves the API on http://localhost:3000
```

For development (auto-reload):

```bash
npm run dev
```

Run the test suite:

```bash
npm test                     # 124 tests across crypto, ledger, scoring, API, fuzz
npm run test:coverage        # coverage report (94%+ overall; see Testing below)
npm run lint
```

## Architecture

```
src/
  crypto/     Ed25519 keys (F1.1), canonical signed-message builders, hex/keccak256 encoding
  ledger/     LedgerEvent schema (I2.1), Merkle tree + hash chain (I2.2), proofs, chain replay
  domain/     claim_hash derivation
  scoring/    Wilson score formula (F4.1), validator_scores persistence (F4.2)
  protocol/   finalization (F3.3), staking/slashing (F5.1), rate limit + evidence complexity (F5.2)
  api/        Express routes for every endpoint below
  db/         SQLite schema + connection (better-sqlite3, file-based, no server to run)
cli/verify.ts Offline verifier (F6.2)
test/         124 tests: unit, API integration (supertest), signature + concurrency fuzzing
openapi.yaml  Full API reference (D8.2)
ATTACK_REPORT.md  Threat model and mitigations (D8.3)
```

**Stack:** TypeScript (strict mode) end to end — server, CLI, and tests share
one codebase, so the crypto and hashing code the CLI uses to independently
verify the server is *literally the same code*, not a reimplementation that
could silently drift. `@noble/curves` (Ed25519) and `@noble/hashes`
(keccak256) for crypto; `better-sqlite3` for storage — synchronous, file-based,
nothing extra to run.

## Trust justification

### Immutability

Every ledger event is chained: `root = hash(prev_root, blockRoot)`, where
`blockRoot` derives from the event's own canonical fields (see
`src/ledger/hash.ts`). Changing *any* historical field — an audit verdict,
a stake amount, an evidence URI — changes that event's hash, which changes
every `root` computed after it. `src/ledger/replay.ts` recomputes the whole
chain from a fixed genesis constant and compares it to what's stored;
`test/ledger/store.test.ts` proves this catches both field tampering and
event deletion. **There is no update or delete path for ledger events in
this codebase** — `LedgerStore` only exposes `appendEvent`. An overturned
claim's original submission event is never rewritten; the overturn is a
*new* audit event layered on top, permanently visible in the validator's
history (see [`getEventsForIdentity`](src/ledger/store.ts) — it returns
every audit ever made against a validator's claims, confirmed or not).

### Accountability

A validator's Ed25519 public key **is** their identity — there's no
separate account system, username, or profile to reset. `GET
/validators/{pubkey}/score` and `GET /verify/{claim_hash}` are both keyed
by pubkey and return the complete, unfiltered history: every claim they
submitted and every audit verdict rendered against it, good or bad. Key
rotation (F1.2, `POST /keys/rotate`) lets a validator hand off to a new key
— the old key signs the new one — but **does not launder reputation**:
`resolveIdentityLineage` walks the rotation chain in both directions so a
retired key's full track record carries forward to the current one (see
`test/api/keys.test.ts`, "treats history across old and new keys as
continuous").

### Economic disincentives

Submitting a claim requires locked stake (`MIN_STAKE_REQUIRED`, auto
-provisioned at `DEFAULT_INITIAL_STAKE` on a validator's first submission —
this is a simulation, not custody of real value). Two independent overturns
within 7 days of the original submission burn 50% of the submitter's
currently locked stake (`src/protocol/slashing.ts`), recorded permanently
on the triggering audit event's `stake_slashed` field. Slashing is
one-shot per claim (idempotent — see `shouldSlashForClaim`) but the
*audit trail* of the overturn is not: it's on the ledger forever, dragging
the validator's Wilson score down via `n` even after the stake is gone.
Sybil-ing many validator identities to escape a bad reputation doesn't
help either: each identity starts at the neutral **0.5** prior (not a
free pass, not a penalty), and needs its own fresh stake and its own
`n >= 5` audited track record before it can out-score the flat prior —
manufacturing that legitimately costs at least as much as behaving honestly
would have. See [ATTACK_REPORT.md](ATTACK_REPORT.md) for collusion-ring
economics in detail.

### Transparency

`GET /verify/{claim_hash}` returns a claim's full context — the
submitter's complete event history, current score, and a Merkle proof
tying that specific claim into the chain — with nothing server-side
required to trust it. The [offline CLI](#offline-verifier-cli-f62)
re-derives a validator's score from raw signed events using the exact same
signature-verification and Wilson-score code the server runs, and prints a
flat **PASS** or **FAIL**. Run it against a JSON file you downloaded
yesterday and it works with zero network access — "offline" is not a
slogan here, it's `--ledger-file`.

## Walkthrough: submit → audit → score

Real, working values (not placeholders) generated via
`scripts/gen-readme-example.ts`. Start the server first (`npm start`),
then:

**1. Submit a claim** (`claim_text` must be ≥ 500 characters; this one
describes an eval run and its evidence):

```bash
curl -s -X POST http://localhost:3000/submit \
  -H "Content-Type: application/json" \
  -d '{
    "claim_text": "On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved 217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried beyond the harness'\''s standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached artifacts.",
    "evidence_uri": "https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json",
    "timestamp": 1772000000000,
    "validator_pubkey": "0x4c8ee26ee906dcbd8dc8f16967ce5fd8817c987fd0516db5ac1d9076203fe9fe",
    "signature": "0x972928baa655bf6871cdbff245bbea0723ed46a80aefa95fc2e60853261af6ab185e10cc780024502679dcf7b53afd498f61ae3a154afd413a9a148a1d57d30c"
  }'
```

→ `201`, event with
`claim_hash = 0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72`.

**2. First auditor confirms:**

```bash
curl -s -X POST http://localhost:3000/audit \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72",
    "audit_verdict": true,
    "timestamp": 1772000060000,
    "validator_pubkey": "0xb5b0304506ef72e1c7ddaa5997206a05e377b8fb3bd73e4041c4a09d03ffafc4",
    "signature": "0x6149ad9c37d39028ff994bfeb419dc02204b78c52256f3bfa3abcb9a84ad0053759d90712670c338ce86cbfbad929a09b410b653149263daf27d4df3a3afe003"
  }'
```

**3. Second auditor confirms** — this is the 2nd audit, so the submitter's
score now recomputes (F3.3), though `n=2 < 5` still returns the neutral
0.5 prior (F4.1):

```bash
curl -s -X POST http://localhost:3000/audit \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72",
    "audit_verdict": true,
    "timestamp": 1772000120000,
    "validator_pubkey": "0x1744d8c382c5d2be9cd6081bbf1206215e17a5acb2039a6d57ae0e8b33005955",
    "signature": "0xad5e937450b765deafb9df6522440dd29243a1d8381901dcc1cef145aef0c2c99751c2c2009b03148a8491e9d7576878f4356ba5783f9bb8b691892217667d0e"
  }'
```

**4. Check the score:**

```bash
curl -s http://localhost:3000/validators/0x4c8ee26ee906dcbd8dc8f16967ce5fd8817c987fd0516db5ac1d9076203fe9fe/score
# {"validator_pubkey":"0x4c8e...","n":2,"confirmations":2,"overturns":0,"score":0.5,...}
```

**5. Verify independently:**

```bash
curl -s http://localhost:3000/verify/0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72
```

returns the full event list, `current_score`, and a `merkle_proof` you can
check with `verifyMerkleProof` (`src/ledger/merkle.ts`) against the
returned `root`.

## API docs

Full request/response schemas and error codes: [openapi.yaml](openapi.yaml).

```bash
npm run docs:serve     # Redoc preview at http://localhost:8080
```

## Offline verifier CLI (F6.2)

```bash
# Against a live server:
npx tsx cli/verify.ts --pubkey 0x4c8ee2...fe --ledger-url http://localhost:3000

# Fully offline, against a previously downloaded mirror:
curl -s http://localhost:3000/validators/0x4c8ee2.../events > mirror.json
npx tsx cli/verify.ts --pubkey 0x4c8ee2...fe --ledger-file ./mirror.json

# Compare against a score you recorded out-of-band (catches a mirror that
# lies about both the events AND its own reported_score consistently):
npx tsx cli/verify.ts --pubkey 0x4c8ee2...fe --ledger-file ./mirror.json --expected-score 0.5
```

It validates every event's Ed25519 signature (rebuilding the exact signed
message per event type — submission/audit, batch, or key rotation — from
`src/crypto/messages.ts`), independently recomputes the Wilson score from
the raw audit verdicts, compares it to the reference score, and prints
`PASS` or `FAIL` with a non-zero exit code on failure. See
`test/cli/verify.test.ts` for an end-to-end example including a live
server round-trip.

## Testing

```
124 tests, 18 files, 0 failures
Coverage: 94.93% stmts / 88.72% branch / 97.4% funcs / 94.93% lines (threshold: 85%)
crypto/keys.ts: 100% line coverage (threshold: 95%)
```

Covers: crypto round-trip + malleability fuzzing (500 iterations),
Merkle tree tamper-evidence, full chain-replay tamper detection
(field edit + event deletion), Wilson score against 6 hand-computed
reference values plus monotonicity/bounds property tests, every API
endpoint's success and failure paths, key-rotation continuity, and
concurrency stress tests proving no lost updates or double-slashing under
`Promise.all`-fired concurrent audits (see `test/fuzz/race.test.ts` for why
this is guaranteed, not just empirically true, given the synchronous
single-threaded design).

## Known limitations

See [ATTACK_REPORT.md](ATTACK_REPORT.md) for the full threat model. Headline
items: this is a **simulation** (stake has no real economic backing, no
external evidence fetching to avoid SSRF), the CLI verifies one validator's
signatures/score but doesn't replay the *entire* global chain from genesis
(that's demonstrated at the store level, `src/ledger/replay.ts`, not wired
into the per-pubkey CLI), and the append-only log here uses one event per
block for API simplicity, trading Merkle-proof succinctness (proof size is
O(events since the claim) rather than O(log n)) for a much simpler append
API.
