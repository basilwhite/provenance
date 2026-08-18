# Attack Report

Threat model for Provenance's anti-collusion and failure-mode protections.
Each section names the concrete module/endpoint responsible.

## 1. Collusion rings

**Attack:** A submitter recruits (or Sybils) auditors to always confirm
their claims, inflating their score risk-free.

**Mitigation:**
- Same-pubkey reuse is rejected (`src/api/routes/audit.ts`: `original.validator_pubkey === body.validator_pubkey` → `403 self_audit_forbidden`). **This is not a self-audit defense against a determined actor:** a second keypair defeats it completely, and — see below — a second keypair costs nothing to acquire. Calling this "self-audit prevention" without that caveat overstates what it does.
- A validator can audit a given claim at most once (`duplicate_audit`, `409`) — a colluding pair can't pad `n` by resubmitting the same "confirm."
- Each new identity in a ring starts at the neutral **0.5** prior and needs `n >= 5` audited claims (`src/scoring/wilson.ts`) before it moves off that prior — a ring pays in **time and audit volume** per Sybil member, not in stake: stake is free (see below), so this is a delay, not a cost.
- **Residual risk:** nothing here stops two *independent, adequately staked* identities from cross-confirming each other indefinitely — the Wilson score can't distinguish a genuine track record from a collusive one. A production system would need auditor-diversity or stake-weighted audit scoring on top of this.

**Stake slashing — not currently an economic backstop:** `src/protocol/slashing.ts` — two independent
overturns within `SLASH_WINDOW_MS` (7 days) of the original submission burn
50% of the submitter's *currently locked* stake (`StakeStore.slash`,
`src/protocol/stakes.ts`), applied exactly once per claim (idempotent via
checking prior audits' `stake_slashed`; verified under concurrent load in
`test/fuzz/race.test.ts`). Slashing compounds and is floor-guarded to
always reach exactly zero (`Math.max(1, …)` in `StakeStore.slash`), so a
validator submitting bad claims on *that one key* is fully depleted within
a handful of overturns and then fails `MIN_STAKE_REQUIRED` on `/submit`.

**Why this isn't a backstop today:** `DEFAULT_INITIAL_STAKE` is
auto-granted free on first submission, with no funding step
(`src/protocol/stakes.ts`). Depleting a key's stake to zero costs the
attacker nothing to reverse — mint a new keypair, get a fresh grant,
resume. The slash removes free stake; it doesn't touch anything the
attacker paid for, because nothing was paid. Until stake costs something
real to acquire, this mechanism deters no one who's willing to re-key.

## 2. Score farming via low-effort claims

**Attack:** Submit many trivial/near-empty claims to rack up `n` cheaply, or spam so many claims that a false one gets lost in the noise.

**Mitigation:**
- `MIN_CLAIM_TEXT_CHARS = 500` (`src/protocol/rateLimit.ts`, `meetsEvidenceComplexity`) rejects short claims with `422 evidence_too_short` on both `/submit` and `/submit/batch` (per-leaf).
- **Deliberate deviation from a literal reading of the spec:** the spec allows "fetch or inspect evidence"; this implementation *inspects `claim_text` length* rather than having the server fetch `evidence_uri` over the network. Server-side fetching of an attacker-supplied URI is a classic SSRF vector (internal metadata endpoints, internal port-scanning via response timing) with no corresponding security benefit here.
- `MAX_SUBMISSIONS_PER_24H = 10` (`countRecentSubmissions`) caps volume per validator per rolling 24h window, measured from **server-observed** time rather than the client-supplied `timestamp`, so the limit can't be dodged by asserting an old timestamp.

## 3. High write costs

**Attack / cost problem:** one ledger event per claim is expensive to append and to prove inclusion for at scale.

**Mitigation:** `POST /submit/batch` (`src/api/routes/batch.ts`, F5.3) bundles up to 50 claims from one validator into a single ledger event carrying `batch_root` (a real Merkle root over each claim's `claim_hash`, `src/ledger/merkle.ts`). `test/api/batch.test.ts` verifies any leaf's proof reconstructs against the stored `batch_root`. It also collapses to **one** rate-limit slot (`type IN ('submission','batch')` in `countRecentSubmissions`) rather than 50 — a genuine cost/throughput win, not just bookkeeping.

**Trade-off:** the ledger chain itself (`LedgerStore.appendEvent`) uses one event per hash-chain "block" for API simplicity (each call returns a root synchronously). A claim's chain-of-custody proof to the latest root is therefore **O(events since that claim)**, not O(log n) — see `src/ledger/proof.ts` `buildChainProof`. A production system batching many events per block would get logarithmic proofs; this trades that for a simpler, always-consistent append API.

## 4. Edge cases

- **Conflicting audits** (one confirm, one overturn): both count. `n = confirmations + overturns` always; the Wilson formula (not a majority vote) absorbs disagreement — see `computeScore`'s property tests (`test/scoring/wilson.test.ts`) for monotonicity across the full confirm/overturn split at fixed `n`.
- **A 3rd+ audit after finalization:** still recomputed into the aggregate (`finalizeClaimIfReady`, `src/protocol/finalize.ts`) — verified in `test/protocol/finalize.test.ts`, "additional audits after finalization still affect score."
- **Key rotation mid-history** (F1.2): `LedgerStore.resolveIdentityLineage` treats old+new keys as one continuous identity for both event history (`getEventsForIdentity`) and score lookup (`ScoreStore.getMostRecentAcrossKeys`, which searches every key in the lineage for the freshest score row rather than assuming the score was re-written at rotation time). Each old key may rotate at most once (`409 duplicate_rotation`), keeping lineages linear rather than branching.
- **Audit verdict is not itself inside the Ed25519 signature payload** (`claimTimestampMessage` signs `claim_hash + timestamp` only, matching the spec exactly). A party with **write access to the raw database** could in principle flip a stored `audit_verdict` without invalidating that row's signature. What *does* catch this: `audit_verdict` is part of `computeLeafHash`'s canonical field set (`src/ledger/types.ts` `LEDGER_EVENT_FIELD_ORDER`), so tampering with it changes that event's ledger-level leaf hash and therefore every `root` computed after it — caught by full chain replay (`src/ledger/replay.ts`, exercised in `test/ledger/store.test.ts`'s tamper-detection suite). **Gap:** the F6.2 CLI as specified only validates signatures + recomputes the score from a single fetched mirror — it does not replay the full global chain from genesis (that would require fetching *every* validator's events, not just one pubkey's, since `prev_root`/`root` chain globally, not per-validator). A mirror that tampers `audit_verdict` **and** its own `reported_score` consistently would pass the CLI's checks. Mitigation: pass `--expected-score` from an independently-recorded reference rather than trusting the mirror's self-reported value, or (not implemented here) extend the CLI with a `--full-ledger-file` mode that runs `replayChain` end to end.
- **Timestamps are client-asserted**, not server-timestamped, for everything except the submission rate-limit window (which deliberately uses server time). The 7-day slash window and Wilson-relevant ordering all trust the caller's `timestamp`. A sophisticated attacker with a still-valid private key could backdate an overturn outside the slash window to dodge stake loss (score impact still applies — `n` isn't backdate-able). Documented limitation, not fixed in this version.
