# Current State

First verified 2026-08-17, re-verified 2026-08-18 after an external observation reported a conflicting repository state (see "How this report was wrong" below). Both passes verified by direct inspection of the filesystem, git remote, and live server — not derived from the build brief or from recollection. Where this document contradicts the brief, this document is right — it was produced by running the checks, not by reading a description.

## Headline finding: the brief's Phase 0 premise is false

The brief states: "As of 17 August 2026 the GitHub repository contained only `LICENSE` and a stub `README.md`. A live API was simultaneously serving eight endpoints... That code exists somewhere and is not in the repo."

Verified against `origin/main` via `git fetch` + `git ls-tree`, and re-verified 2026-08-18 via `git ls-remote` (live network round-trip), the GitHub REST API, `raw.githubusercontent.com`, and a direct fetch of the `github.com/basilwhite/provenance` web page itself: this is not the current state. `github.com/basilwhite/provenance` on `main` (commit `736aba2` as of 2026-08-18) contains the full TypeScript reference implementation, the full PHP production port, both test suites, and supporting docs. Local `HEAD` and `origin/main` are identical — nothing is staged, nothing is ahead, nothing is orphaned on a server with no copy in git. The code the live API runs is the code in the repo, deployed by manually copying `php-api/` to the host over SFTP.

There is no "find the missing code" problem. There is a different, real problem below (license history) that the brief's own framing would have missed if Phase 0 had been skipped.

## How this report was wrong

It wasn't, substantively — but the *form* of the original report had a real gap, and a second external observation on 2026-08-18 (an anonymous fetch reporting 1 commit, exactly `LICENSE` + `README.md`, CC0-1.0 badge) exposed it.

That second observation was not noise and was not dismissed. It was run down to an exact match: commit `880a66d` (the second parent of merge commit `fd3226f` — GitHub's own auto-generated repo-creation commit, from before this project's real content was merged in) has `git rev-list --count` = 1, tree = exactly `LICENSE` and `README.md`, and `LICENSE` content = literal "Creative Commons Legal Code / CC0 1.0 Universal." That is this repository's real history — just not its current state. Whatever produced the "anonymous HTTP fetch" returned a stale snapshot frozen at that original commit, not a misread of anything current.

Eleven fresh command outputs on 2026-08-18 (`git remote -v`, `git fetch --verbose`, `git ls-remote`, `git rev-parse` on both `HEAD` and `origin/main`, `git log` both directions, `git show` LICENSE from both refs, `git status --porcelain --branch`, `git ls-tree`) all returned exit 0 and all agreed with the original report. The actual gap: the original 2026-08-17 report described its verification narratively — "verified via six independent channels" — instead of pasting the raw command output into this document. That's why a stale external observation turned into a second full investigation instead of an immediate, mechanical diff against evidence already on the record. The check that would have caught it sooner: always paste raw output for any claim about public state, so a future contradiction can be resolved by reading this file instead of re-running everything from scratch. Applying that from here forward, per the standing rule now in force for this project.

## Raw evidence, 2026-08-18 (paste, not summary)

```
$ git remote -v
origin  https://github.com/basilwhite/provenance.git (fetch)
origin  https://github.com/basilwhite/provenance.git (push)
EXIT=0

$ git fetch origin --verbose
From https://github.com/basilwhite/provenance
 = [up to date]      main       -> origin/main
EXIT=0

$ git ls-remote origin
736aba28c4fc2ec0bb0175a423e43fc0a3d8c4c5        HEAD
736aba28c4fc2ec0bb0175a423e43fc0a3d8c4c5        refs/heads/main
EXIT=0

$ git rev-parse HEAD origin/main
736aba28c4fc2ec0bb0175a423e43fc0a3d8c4c5
736aba28c4fc2ec0bb0175a423e43fc0a3d8c4c5
EXIT=0

$ git log --oneline origin/main..HEAD
(empty)
EXIT=0

$ git show origin/main:LICENSE | head -5
MIT License

Copyright (c) 2026 Basil White
EXIT=0

$ git status --porcelain=v1 --branch
## main...origin/main
EXIT=0

$ git ls-tree --name-only origin/main
.eslintrc.json .gitignore ATTACK_REPORT.md DEMO_VIDEO_SCRIPT.md LICENSE README.md
cli demo-commands.sh docs openapi.yaml package-lock.json package.json php-api
scripts src test tsconfig.json tsconfig.test.json vitest.config.ts
EXIT=0

$ curl -s https://api.github.com/repos/basilwhite/provenance | grep pushed_at
"pushed_at": "2026-08-17T04:34:21Z"

$ git rev-list --count 880a66d
1
$ git ls-tree --name-only 880a66d
LICENSE README.md
$ git show 880a66d:LICENSE | head -3
Creative Commons Legal Code
CC0 1.0 Universal
```

## Workspace enumeration

`C:\c.code-workspace` is a one-line VS Code workspace file whose sole folder root is `"."` — i.e., all of `C:\`. Top-level enumeration of `C:\` found no second copy of this project and no orphaned Provenance code anywhere outside `C:\provenance`. Specifically checked and empty of Provenance content: `C:\c` (contains only an empty `Users` subfolder, unrelated), `C:\basilwhite.com` (the live site's document root — a large personal file store with no `provenance/` subfolder; the deployed API was never locally mirrored there, only pushed via SFTP from `C:\provenance\php-api`). A recursive filename search for `*provenance*` across `C:\` (depth 3) returned nothing outside `C:\provenance` itself.

The rest of `C:\` is unrelated personal and other-project material (Ableton, GarageMahal, IVN, various document folders) and is not itemized here since none of it references Provenance.

## What exists

Single project directory: `C:\provenance`, a git repository with one remote (`origin` → `github.com/basilwhite/provenance.git`).

Two parallel implementations of the same protocol:

- **TypeScript reference** (`src/`, `test/`, `cli/`): Express API, better-sqlite3 storage, Vitest suite. **124 tests, 18 files, 0 failures**; coverage 94.93% stmts / 88.72% branch / 97.4% funcs / 94.93% lines (re-run 2026-08-18, exact match to what `README.md` already claimed — that figure was correct).
- **PHP production port** (`php-api/`): front-controller router, PDO/MySQL storage, PHPUnit suite. **130 tests, 468 assertions, 0 failures**; 93.37% line coverage (re-run 2026-08-18 with Xdebug). Both README.md and php-api/README.md previously stated 125 tests / 93.47% — now corrected in both files and in `docs/CLAIMS_AUDIT.md`.

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

## License history, and the decision now made

GitHub's own repo-creation flow generated the initial commit (`880a66d`) with a CC0-1.0 `LICENSE` and a placeholder README, before this project's real content existed. Reconciling that against the already-written local implementation used `git merge --allow-unrelated-histories -X ours` (commit `fd3226f`), which kept the local MIT license and discarded GitHub's CC0 one. If CC0 at repo-creation time was an intentional choice, that merge reversed it as a side effect of conflict resolution, not as a decision anyone made on purpose.

That gap is now closed by an explicit decision, not left as a discrepancy: implementation code (`src/`, `php-api/`, `cli/`, `test/`) stays **MIT** — it was already in place, and no stated preference for Apache-2.0's explicit patent grant was given. `spec/` (the protocol specification and test vectors, once Phase 2 writes them) is **CC0-1.0**, added at `spec/LICENSE`, extracted byte-for-byte from GitHub's own original `880a66d:LICENSE` rather than retyped. `README.md` now has a License section stating the split. Neither license achieves "owned by no one" on its own — a license governs copying, not who operates the only running instance; that's tracked separately as D-9 (external anchoring) and D-10 (replication).

The previously-flagged test-count drift (125/93.47%/249 vs. actual 130/93.37%/254) is corrected in `README.md`, `php-api/README.md`, and detailed in `docs/CLAIMS_AUDIT.md`.

## Existing spec documents

No document meeting the Phase 2 bar ("complete enough that a stranger could write a second interoperable implementation from it alone") exists anywhere in the workspace. The closest artifact is `openapi.yaml`: it documents the eight HTTP endpoints, request/response JSON shapes, and field patterns, but it is a REST wire-format spec, not a protocol spec. It does not define canonical encoding, domain separation, the exact hash preimage construction independent of any one language's string concatenation, the scoring function's derivation or version history, or third-party test vectors. `ATTACK_REPORT.md` documents known attack surface and mitigations for the current design but is not a specification either. Phase 2 starts from nothing, not from a draft.

## Verified facts about the current implementation, for Phase 1 to work from

These are observations of what the code does, not judgments of whether it's right — that's Phase 1's job. Confirmed by reading `src/` (TypeScript reference; the PHP port matches it by construction and by the cross-verification done during the port):

- **Scoring** (`src/scoring/wilson.ts`): computes `p_hat = (confirmations + 1) / (n + 2)` — Laplace/Krichevsky–Trofimov-style smoothing — then substitutes that smoothed `p_hat` into the standard Wilson lower-bound formula, which is derived assuming a raw sample proportion. The result is neither textbook Wilson nor Agresti-Coull. Returns exactly `0.5` when `n < 5`. Matches the brief's D-8 description exactly.
- **Self-audit check** (`src/api/routes/audit.ts`): literal `original.validator_pubkey === body.validator_pubkey` string comparison. No stake requirement, no cost, and no identity check beyond key equality gates auditing — a second keypair held by the same person defeats it completely, with zero friction. Matches D-4.
- **Auditor stake** (`src/api/routes/audit.ts`, `src/protocol/stakes.ts`): auditors are not required to hold or risk any stake to submit an audit. Only the original submitter's stake is at risk (`stakeStore.slash(original.validator_pubkey)` on overturn). Matches the brief's "auditors stake nothing and risk nothing."
- **Stake currently provides zero Sybil resistance, because it costs nothing to obtain.** (`src/protocol/stakes.ts`, explicit in-code comment): "This is a simulation: there is no real token custody, deposits are auto-provisioned so `/submit` is exercisable without a funding step." Every new pubkey is auto-granted `DEFAULT_INITIAL_STAKE = 10` units on first submission, at zero cost, with no verification the requester is a distinct person. This is sharper than the brief's framing ("slashing 50 percent of a monetary stake excludes people with no capital") suggests: the problem isn't that real stake is too expensive for the audience — it's that stake has never been real. Slashing burns a free grant; the attacker mints a new key and receives another one immediately. The only cost that remains is *time* (rebuilding `n >= 5` audits from the neutral 0.5 prior), which is identical for an honest newcomer and a re-keying attacker and is therefore not a deterrent at all. D-6 needs to address this directly, not "what replaces money" — see `docs/CLAIMS_AUDIT.md` rows 1 and 9 for where this claim was live and public, and has now been corrected.
- **Key rotation** (`src/api/routes/keys.ts`): old key signs the new key (`rotationMessage(old_pubkey, new_pubkey)`), verified against `old_pubkey`. Rejects rotating an already-rotated `old_pubkey` and rejects a `new_pubkey` that's already part of another lineage — lineages are enforced to be a simple chain, never a branch or merge, which is what score-continuity resolution depends on. No mechanism anywhere binds a key or a lineage to a real-world identity. Matches D-4's second half exactly: nothing here could ever prove a key belongs to a specific job applicant.
- **External anchoring**: none. Grepped `src/` and `php-api/src/` for witness, checkpoint, Signed-Tree-Head, or Certificate-Transparency terminology — nothing. Merkle roots exist only inside the operator-controlled MySQL/SQLite store. Matches the brief's D-9 premise exactly: today, the operator could rewrite history and the offline verifier would still print PASS, because nothing outside the operator's own database holds a root at time T.
- **Identity binding**: none. Grepped for KYC / identity-verification / employer-facing terminology — nothing. A pseudonymous key today has no path to being demonstrated as belonging to a specific person.
- **Claim shape**: one shape only — `claim_text` (≥500 chars, enforced in `src/api/validators.ts` equivalent and the PHP `Validators.php`), one `evidence_uri`, one timestamp. No claim-type field, no per-item/batch-friendly lightweight shape beyond `POST /submit/batch`, which is a batch of full-size claims, not a smaller unit. Matches D-12's premise: the current shape fits an audit report and nothing smaller.

## What this means for Phase 1

Every open decision in the brief (D-1 through D-12) has a real, verified anchor in the current code — none of them are hypothetical. `docs/CLAIMS_AUDIT.md` extends this into every public-facing claim, not just the design-decision list: it audits the live landing page, both READMEs, `ATTACK_REPORT.md`, code comments, and the GitHub repository description against actual code behavior, with a verdict and evidence citation for each. The license question is now a closed decision (see above), not an open item.

No protocol code changes have been made in this pass. This document is the Phase 0 deliverable, corrected 2026-08-18 against a second round of scrutiny.
