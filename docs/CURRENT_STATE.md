# Current State

Verified 2026-08-17 by direct inspection of the filesystem, git remote, and live server. Not derived from the build brief. Where this document contradicts the brief, this document is right — it was produced by running the checks, not by reading a description.

## Headline finding: the brief's Phase 0 premise is false

The brief states: "As of 17 August 2026 the GitHub repository contained only `LICENSE` and a stub `README.md`. A live API was simultaneously serving eight endpoints... That code exists somewhere and is not in the repo."

Verified against `origin/main` via `git fetch` + `git ls-tree`: this is not the current state. `github.com/basilwhite/provenance` on `main` (commit `80481cc`) contains the full TypeScript reference implementation, the full PHP production port, both test suites, and supporting docs. Local `HEAD` and `origin/main` are identical — nothing is staged, nothing is ahead, nothing is orphaned on a server with no copy in git. The code the live API runs is the code in the repo, deployed by manually copying `php-api/` to the host over SFTP.

This may mean the brief was drafted before a deploy that has since landed, or it may be testing whether Phase 0 is actually performed rather than assumed. Either way: there is no "find the missing code" problem. There is a different, real problem below (license mismatch) that the brief's own framing would have missed if Phase 0 had been skipped.

## Workspace enumeration

`C:\c.code-workspace` is a one-line VS Code workspace file whose sole folder root is `"."` — i.e., all of `C:\`. Top-level enumeration of `C:\` found no second copy of this project and no orphaned Provenance code anywhere outside `C:\provenance`. Specifically checked and empty of Provenance content: `C:\c` (contains only an empty `Users` subfolder, unrelated), `C:\basilwhite.com` (the live site's document root — a large personal file store with no `provenance/` subfolder; the deployed API was never locally mirrored there, only pushed via SFTP from `C:\provenance\php-api`). A recursive filename search for `*provenance*` across `C:\` (depth 3) returned nothing outside `C:\provenance` itself.

The rest of `C:\` is unrelated personal and other-project material (Ableton, GarageMahal, IVN, various document folders) and is not itemized here since none of it references Provenance.

## What exists

Single project directory: `C:\provenance`, a git repository with one remote (`origin` → `github.com/basilwhite/provenance.git`).

Two parallel implementations of the same protocol:

- **TypeScript reference** (`src/`, `test/`, `cli/`): Express API, better-sqlite3 storage, Vitest suite. **124 tests, 18 files, 0 failures** (re-run to confirm, not taken from a stale claim).
- **PHP production port** (`php-api/`): front-controller router, PDO/MySQL storage, PHPUnit suite. **130 tests, 468 assertions, 0 failures** (re-run to confirm). This is 5 tests more than the 125 the root `README.md` currently states — the README is stale by one commit (the root-landing-page work).

Both are committed. Both are pushed. `git status` is clean.

## What runs

Live at `https://basilwhite.com/provenance/` on Network Solutions shared hosting (Apache + PHP 8.3 + MySQL). Confirmed live moments ago:

- `GET /health` → `{"status":"ok"}`
- `GET /verify/{nonexistent claim_hash}` → clean `404`, no PHP error page
- `GET /` → generated HTML documentation page (not part of the protocol; a docs convenience)

Deployment is **manual**: `composer install --no-dev`, then SFTP upload of `index.php`, `.htaccess`, `src/`, `vendor/`, `composer.json/.lock`, and a gitignored `config.local.php` holding real DB credentials. No CI/CD exists — no `.github/workflows`, no automated deploy on push. One person (the repo owner) holds the only credentials that can update the running instance.

The TypeScript reference does not run in production. It runs locally via `npx tsx src/api/server.ts` for development and as the executable spec that the PHP port was verified against.

## What is committed

Everything under `src/`, `test/`, `cli/`, `php-api/` (including `php-api/tests/`), `openapi.yaml`, `README.md`, `ATTACK_REPORT.md`, `DEMO_VIDEO_SCRIPT.md`, `demo-commands.sh`, `package.json` / `package-lock.json`, `LICENSE`. `php-api/vendor/` is deliberately **not** committed (installed at deploy time per `php-api/README.md`) but **is** uploaded directly to the host outside git. `config.local.php` is never committed and never uploaded via git — it is generated locally and SFTP'd directly.

## What is orphaned or inconsistent

Nothing code-level. Two real inconsistencies, both administrative, both requiring an explicit owner decision — not silently fixed here:

1. **License mismatch.** The brief says "License: CC0-1.0. Keep it that way." The actual committed `LICENSE` (verified on both local `HEAD` and `origin/main`) is **MIT**, not CC0-1.0. History explains how: GitHub's own repo-creation flow generated an initial commit with a CC0 `LICENSE` and a placeholder README; reconciling that against the already-written local implementation used `git merge --allow-unrelated-histories -X ours`, which kept the local MIT license and discarded GitHub's CC0 one. If the CC0 choice at repo-creation time was intentional, this merge silently reversed it. This needs an explicit decision, not an assumption in either direction — see Phase 1.
2. **README test-count drift.** `README.md` states "125 PHPUnit tests," "249 combined tests," and 93.47% PHP line coverage. Actual current count is 130 PHPUnit tests (468 assertions) / 254 combined. The 93.47% coverage figure was not re-verified in this pass and should be treated as unconfirmed until a coverage run is re-run against current `php-api/src/`.

## Existing spec documents

No document meeting the Phase 2 bar ("complete enough that a stranger could write a second interoperable implementation from it alone") exists anywhere in the workspace. The closest artifact is `openapi.yaml`: it documents the eight HTTP endpoints, request/response JSON shapes, and field patterns, but it is a REST wire-format spec, not a protocol spec. It does not define canonical encoding, domain separation, the exact hash preimage construction independent of any one language's string concatenation, the scoring function's derivation or version history, or third-party test vectors. `ATTACK_REPORT.md` documents known attack surface and mitigations for the current design but is not a specification either. Phase 2 starts from nothing, not from a draft.

## Verified facts about the current implementation, for Phase 1 to work from

These are observations of what the code does, not judgments of whether it's right — that's Phase 1's job. Confirmed by reading `src/` (TypeScript reference; the PHP port matches it by construction and by the cross-verification done during the port):

- **Scoring** (`src/scoring/wilson.ts`): computes `p_hat = (confirmations + 1) / (n + 2)` — Laplace/Krichevsky–Trofimov-style smoothing — then substitutes that smoothed `p_hat` into the standard Wilson lower-bound formula, which is derived assuming a raw sample proportion. The result is neither textbook Wilson nor Agresti-Coull. Returns exactly `0.5` when `n < 5`. Matches the brief's D-8 description exactly.
- **Self-audit check** (`src/api/routes/audit.ts`): literal `original.validator_pubkey === body.validator_pubkey` string comparison. No stake requirement, no cost, and no identity check beyond key equality gates auditing — a second keypair held by the same person defeats it completely, with zero friction. Matches D-4.
- **Auditor stake** (`src/api/routes/audit.ts`, `src/protocol/stakes.ts`): auditors are not required to hold or risk any stake to submit an audit. Only the original submitter's stake is at risk (`stakeStore.slash(original.validator_pubkey)` on overturn). Matches the brief's "auditors stake nothing and risk nothing."
- **Stake is not capital.** (`src/protocol/stakes.ts`, explicit in-code comment): "This is a simulation: there is no real token custody, deposits are auto-provisioned so `/submit` is exercisable without a funding step." Every new pubkey is auto-granted `DEFAULT_INITIAL_STAKE = 10` units on first submission, at zero cost, with no verification the requester is a distinct person. This is a sharper problem than the brief's framing ("slashing 50 percent of a monetary stake excludes people with no capital") suggests: the current stake has never been monetary, so it currently provides **zero** economic deterrent against Sybil attacks — an attacker can mint unlimited free-stake identities. D-6 needs to address this directly, not just "what replaces money."
- **Key rotation** (`src/api/routes/keys.ts`): old key signs the new key (`rotationMessage(old_pubkey, new_pubkey)`), verified against `old_pubkey`. Rejects rotating an already-rotated `old_pubkey` and rejects a `new_pubkey` that's already part of another lineage — lineages are enforced to be a simple chain, never a branch or merge, which is what score-continuity resolution depends on. No mechanism anywhere binds a key or a lineage to a real-world identity. Matches D-4's second half exactly: nothing here could ever prove a key belongs to a specific job applicant.
- **External anchoring**: none. Grepped `src/` and `php-api/src/` for witness, checkpoint, Signed-Tree-Head, or Certificate-Transparency terminology — nothing. Merkle roots exist only inside the operator-controlled MySQL/SQLite store. Matches the brief's D-9 premise exactly: today, the operator could rewrite history and the offline verifier would still print PASS, because nothing outside the operator's own database holds a root at time T.
- **Identity binding**: none. Grepped for KYC / identity-verification / employer-facing terminology — nothing. A pseudonymous key today has no path to being demonstrated as belonging to a specific person.
- **Claim shape**: one shape only — `claim_text` (≥500 chars, enforced in `src/api/validators.ts` equivalent and the PHP `Validators.php`), one `evidence_uri`, one timestamp. No claim-type field, no per-item/batch-friendly lightweight shape beyond `POST /submit/batch`, which is a batch of full-size claims, not a smaller unit. Matches D-12's premise: the current shape fits an audit report and nothing smaller.

## What this means for Phase 1

Every open decision in the brief (D-1 through D-12) has a real, verified anchor in the current code — none of them are hypothetical. The license question (not in the brief's D-list) needs to be added to Phase 1 as an explicit item, since it is a live, unresolved discrepancy between stated intent and committed fact, and "owned by no one" makes it load-bearing.

No protocol code changes have been made in this pass. This document is the Phase 0 deliverable.
