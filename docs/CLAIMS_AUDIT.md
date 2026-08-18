# Claims Audit

Every factual or trust-related assertion found in the live landing page, `README.md`, `php-api/README.md`, `ATTACK_REPORT.md`, the GitHub repository description, and code comments that state guarantees. Checked against actual code behavior on 2026-08-18. Corrections have already been applied to the source files listed in each row's "Corrected in" column — this document is the record of why, not a pending to-do list.

Verdict key: **TRUE** (matches code exactly), **PARTIAL** (mechanism exists but the claim overstates what it does), **FALSE** (claim does not hold), **UNVERIFIABLE** (no code path could confirm or deny it), **NOT CLAIMED** (checked for; does not appear in any public artifact).

## 1. "public, tamper-evident, economically-backed reputation"

- **Source:** `README.md`, opening paragraph (pre-fix).
- **Code path that would back it:** "tamper-evident" → `src/ledger/hash.ts` (chained roots) + `src/ledger/replay.ts` (tamper detection). "Economically-backed" → `src/protocol/stakes.ts` (stake custody).
- **Verdict:** **PARTIAL** — "tamper-evident" is TRUE; "economically-backed" is FALSE.
- **Evidence:** `src/protocol/stakes.ts:18-19` — in-code comment: *"This is a simulation: there is no real token custody, deposits are auto-provisioned so `/submit` is exercisable without a funding step."* `DEFAULT_INITIAL_STAKE = 10` (line 7) is granted automatically on first submission, no payment, no verification of a distinct person.
- **Corrected in:** `README.md` opening paragraph now reads "public, tamper-evident reputation" — the economic claim was removed, not softened.

## 2. "Anyone can independently recompute a validator's score from raw events without trusting this server"

- **Source:** `README.md`, opening paragraph (pre-fix).
- **Code path:** `cli/verify.ts`.
- **Verdict:** **PARTIAL**.
- **Evidence:** `cli/verify.ts:78-89` (`fetchMirror`) pulls the event list from `{ledgerUrl}/validators/{pubkey}/events` — i.e. from this same server, or a file the user downloaded from it earlier. `recomputeScore` (lines 126-148) never reads `root` or `prev_root` at all — grep-confirmed, those fields exist on the `RawEvent` type (lines 22-24) but are not used in any verification logic anywhere in the file. The comparison at line 170 defaults to `mirror.reported_score` — a value that came from the same payload being checked. Recomputation proves the signatures are genuine and the score arithmetic is self-consistent. It proves nothing about whether the server sent every event that exists.
- **Corrected in:** `README.md` opening paragraph now states this precisely: proves "arithmetically consistent," not complete.

## 3. "Don't Trust, Verify." / "If the numbers don't match, the server is lying (or compromised) — you'll know."

- **Source:** `README.md` callout (pre-fix); live landing page pitch box (pre-fix, both PHP and TS copies).
- **Code path:** `cli/verify.ts` `main()`, lines 150-189.
- **Verdict:** **FALSE** as an implication (the converse — *matching numbers means the server isn't lying* — does not hold).
- **Evidence:** Same as #2. `ATTACK_REPORT.md` (pre-fix, line 49) already documented this precisely, in a doc most readers of the pitch line never open: *"A mirror that tampers `audit_verdict` **and** its own `reported_score` consistently would pass the CLI's checks."* An operator who omits unfavorable events (not fabricates — omits real ones) from one requester's mirror, while keeping the remaining signatures genuine and the reported score internally consistent with the trimmed list, produces a CLI run that prints `PASS`.
- **Corrected in:** `README.md` callout now says "for what it currently covers" and names the exact gap with a link to `ATTACK_REPORT.md#4-edge-cases`. Landing page pitch box (`php-api/scripts/generate-landing-page.php`, regenerated into `php-api/src/Api/LandingPage.php` and `src/api/landing-page.ts`) rewritten the same way.

## 4. GitHub repository description: "all anchored to an append-only, Merkle-rooted ledger"

- **Source:** `github.com/basilwhite/provenance` repo description field (live, via `GET /repos/basilwhite/provenance`).
- **Code path:** would require some structure holding a root independent of the operator — a witness, a Certificate-Transparency-style Signed Tree Head, a public-chain checkpoint.
- **Verdict:** **FALSE**. "Chained" is true; "anchored" implies external fixation, and there is none.
- **Evidence:** Phase 0 (`docs/CURRENT_STATE.md`) grepped `src/` and `php-api/src/` for witness/checkpoint/Signed-Tree-Head/Certificate-Transparency terminology — nothing. Merkle roots exist only inside the operator-controlled MySQL/SQLite store.
- **Corrected in:** not yet — this is repository *metadata*, not a file in the repo. Proposed corrected text: *"Provenance — a ledger-backed AI validator reputation system. AI validators sign and submit claims, get audited by peers (confirm/overturn), earn a Wilson-derived score, and put stake at risk of slashing — chained by Merkle roots inside the operator's own store today (not yet externally anchored), verifiable offline for signature and arithmetic consistency."* Changing the repo description requires GitHub UI/API access this session doesn't have a credential for — flagged to the owner, not applied.

## 5. GitHub repository description: "an offline 'don't trust, verify' CLI"

- **Source:** same GitHub description field.
- **Verdict:** **PARTIAL** — the CLI exists and runs offline against a file; see #2/#3 for what it doesn't cover.
- **Corrected in:** folded into the same proposed description text in #4 ("verifiable offline for signature and arithmetic consistency" rather than the unqualified "don't trust, verify").

## 6. GitHub repository description: "earn a Wilson-score-based trust rating"

- **Source:** same GitHub description field.
- **Code path:** `src/scoring/wilson.ts`.
- **Verdict:** **PARTIAL**.
- **Evidence:** `src/scoring/wilson.ts:28` computes `pHat = (confirmations + 1) / (n + 2)` — Laplace/Krichevsky–Trofimov smoothing — then substitutes that smoothed value into the standard Wilson lower-bound formula (lines 29-32), which is derived assuming a raw sample proportion. The result is neither textbook Wilson nor Agresti-Coull; it doesn't have a standard name. "Wilson-derived" is defensible; "Wilson-score" invites the reader to assume the textbook interval.
- **Corrected in:** proposed description text in #4 uses "Wilson-derived score" rather than "Wilson-score-based trust rating."

## 7. GitHub repository description: "put stake at risk that gets slashed for bad calls"

- **Source:** same GitHub description field.
- **Verdict:** **PARTIAL** — mechanically true, discloses nothing about the stake being free.
- **Evidence:** same as #1.
- **Corrected in:** proposed description text in #4 says "stake at risk of slashing" without asserting economic cost, and the fuller caveat lives in the linked docs rather than the 350-character description field.

## 8. README "Immutability": "There is no update or delete path for ledger events in this codebase"

- **Source:** `README.md`, Trust justification § Immutability (pre-fix).
- **Code path:** `src/ledger/store.ts` (`LedgerStore`, TS) / `php-api/src/Ledger/Store.php` + `php-api/src/Db/Connection.php` (`createAppendOnlyTriggers`, PHP/production).
- **Verdict:** **PARTIAL** — true for application code; not enforced at the storage layer on the actual live deployment.
- **Evidence:** `php-api/src/Db/Connection.php`'s `createAppendOnlyTriggers` wraps `CREATE TRIGGER` in try/catch and silently degrades on `\PDOException`. Per `docs/CURRENT_STATE.md`, this hit MySQL error 1419 (`CREATE TRIGGER` requires `SUPER` or `log_bin_trust_function_creators`) on the real production database. The trigger is not installed on `basilwhite.com/provenance`'s actual database today. Whoever holds the MySQL credentials (the operator) can issue `UPDATE`/`DELETE` directly against `ledger_events`, and nothing at the storage layer refuses it.
- **Corrected in:** `README.md` § Immutability now states this explicitly, including that only an after-the-fact `src/ledger/replay.ts` run (not automatic, not continuous) would catch the resulting hash mismatch.

## 9. README "Economic disincentives": Sybil-ing "doesn't help... manufacturing that legitimately costs at least as much as behaving honestly would have"

- **Source:** `README.md`, Trust justification § Economic disincentives (pre-fix).
- **Code path:** `src/protocol/stakes.ts` (`DEFAULT_INITIAL_STAKE`), `src/scoring/wilson.ts` (neutral prior at `n < 5`).
- **Verdict:** **FALSE**.
- **Evidence:** `DEFAULT_INITIAL_STAKE = 10` is granted free, automatically, on first submission (`StakeStore.ensureProvisioned`, `src/protocol/stakes.ts:31-41`). Minting a new identity after a slash costs literally nothing in stake. The only remaining friction — waiting for `n >= 5` audits before the fresh identity out-scores the flat 0.5 prior — is a *time* cost, identical for an honest newcomer and a re-keying attacker. It is not an economic cost and does not make Sybil-ing "cost at least as much as behaving honestly."
- **Corrected in:** `README.md` § "Stake and slashing — mechanism implemented, economic disincentive **not** implemented" (renamed from "Economic disincentives"). `ATTACK_REPORT.md` § 1 similarly corrected: "a ring pays in time and audit volume... not in stake: stake is free."

## 10. README/landing page "Transparency": "nothing server-side required to trust it" / "No trust required"

- **Source:** `README.md` § Transparency (pre-fix); landing page flow-step 5 and trust-justification summary (pre-fix).
- **Verdict:** **FALSE** as stated; same root cause as #2/#3/#4 — the Merkle proof returned by `/verify/{claim_hash}` is checked against a `root` the same operator-controlled server also computed.
- **Corrected in:** `README.md` § renamed "Transparency — proves consistency, not completeness," rewritten to state exactly this. Landing page flow-step 5 now reads "proving consistency, not that history wasn't rewritten"; the trust-justification summary line no longer says "no trust required."

## 11. ATTACK_REPORT.md: "Self-audit is rejected outright"

- **Source:** `ATTACK_REPORT.md` § 1, Mitigation bullet 1 (pre-fix).
- **Code path:** `src/api/routes/audit.ts:42-44` (TS) / `php-api/src/Api/Routes/AuditRoute.php` equivalent.
- **Verdict:** **PARTIAL** as an unqualified claim; the check itself is real but trivially defeated.
- **Evidence:** `original.validator_pubkey === body.validator_pubkey` is literal string equality on the pubkey. A second Ed25519 keypair — free to generate, no registration, no identity binding anywhere in the codebase (`docs/CURRENT_STATE.md` confirms no KYC/identity-verification terminology exists in `src/` or `php-api/src/`) — defeats it completely and at zero cost.
- **Corrected in:** `ATTACK_REPORT.md` bullet rewritten: "Same-pubkey reuse is rejected... This is not a self-audit defense against a determined actor: a second keypair defeats it completely, and a second keypair costs nothing to acquire."

## 12. Test-count and coverage figures: "125 PHPUnit tests, 93.47% line coverage" / "249 combined tests"

- **Source:** `README.md` (pre-fix, two locations), `php-api/README.md` (pre-fix).
- **Verdict:** **FALSE** — stale, not a code-behavior mismatch.
- **Evidence:** Re-run 2026-08-18: `php vendor/bin/phpunit` → `OK (130 tests, 468 assertions)`. `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` → `Lines: 93.37% (676/724)`. 124 TS + 130 PHP = 254, not 249. The count drifted because a landing-page feature and its tests were added in a commit after the README's numbers were last updated, and nothing re-derives these numbers automatically — they're hand-maintained prose.
- **Corrected in:** `README.md` (both locations) and `php-api/README.md` now say 130 PHPUnit / 93.37% / 254 combined, dated 2026-08-18, with a forward pointer to this document so the next drift is at least explained rather than silently wrong again.

## 13. README "Testing" § TS figures: "124 tests, 18 files, 0 failures" / "94.93% stmts / 88.72% branch / 97.4% funcs / 94.93% lines"

- **Verdict:** **TRUE**.
- **Evidence:** Re-run 2026-08-18: `npx vitest run` → `Test Files 18 passed (18)`, `Tests 124 passed (124)`. `npx vitest run --coverage` → `All files 94.93 | 88.72 | 97.4 | 94.93` — exact match, to the decimal. No correction needed.

## 14. "Blinded peer audit"

- **Source:** checked `README.md`, `ATTACK_REPORT.md`, `openapi.yaml`, the live landing page, and the GitHub description for "blind" (case-insensitive). Zero matches anywhere.
- **Verdict:** **NOT CLAIMED**. This phrase is a mission-brief aspiration, not a live assertion made by any public artifact in this repository. There is nothing to correct because nothing asserts it.
- **Action:** none in this document. Tracked as open design decision D-2 (blinding vs. transparency) for Phase 1, since the ledger publishing `validator_pubkey` on every event means blinding — if implemented — could only ever hold at audit time, not after `/verify` makes the pairing public.

## 15. "The score measures accuracy"

- **Source:** checked all public artifacts for "accura*." The only matches are inside example `claim_text` strings in curl examples (README.md, landing page) — sample data illustrating what a *validator's own claim* might assert about *their AI eval run*, unrelated to what Provenance's score measures.
- **Verdict:** **NOT CLAIMED** / **TRUE** by omission. Every actual description of the score (README "Wilson-lower-bound estimate," `ATTACK_REPORT.md` "the Wilson score can't distinguish a genuine track record from a collusive one") already frames it as agreement among auditors, not ground-truth accuracy. No correction needed — flagged here to show the check was actually made, per the explicit instruction to scrutinize this.

## 16. Key rotation "does not launder reputation"

- **Source:** `README.md` § Accountability.
- **Code path:** `src/api/routes/keys.ts`, `LedgerStore.resolveIdentityLineage`.
- **Verdict:** **TRUE**, narrowly — and the scope of that narrowness matters.
- **Evidence:** `test/api/keys.test.ts` ("treats history across old and new keys as continuous") confirms a *legitimate* rotation (old key signs new key) carries the full track record forward — a rotation cannot reset a damaged score to neutral. This claim says nothing about **key sale**: whoever holds the private key today is the validator of record, undetectably, and rotation can hand a clean-looking identity to a buyer who never earned it. That is a distinct, real, currently-unaddressed vector — tracked as design decision D-5, not contradicted by this specific claim, which is accurate as far as it goes.
- **Corrected in:** no text change; this row exists to record that the claim was checked and confirmed accurate within its actual scope.

## Summary of files changed

| File | What changed |
|---|---|
| `README.md` | Removed "economically-backed"; rewrote the "Don't Trust, Verify" callout; corrected test/coverage counts (2 places); qualified the Immutability claim with the production trigger gap; renamed and rewrote "Economic disincentives" → stake section; renamed and rewrote "Transparency"; added a License section. |
| `php-api/README.md` | Corrected test/coverage counts; qualified the self-audit claim. |
| `ATTACK_REPORT.md` | Qualified the self-audit mitigation bullet; corrected "real stake" → "time, not stake"; renamed and rewrote the slashing backstop paragraph. |
| `php-api/scripts/generate-landing-page.php` → regenerated `php-api/src/Api/LandingPage.php` → re-extracted `src/api/landing-page.ts` | Rewrote the pitch box, flow-step 2 and 5, the trust-justification summary line, and added a second "Known gap" paragraph (anchoring), alongside the stake gap already fixed. |
| GitHub repository description | **Not changed** — this session has no credential scoped to edit repository metadata via the GitHub API. Proposed replacement text is in row 4 above; needs the owner to apply it via repo Settings, or explicit authorization to attempt it via API. |
