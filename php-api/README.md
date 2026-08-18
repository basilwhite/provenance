# Provenance — PHP API (production deployment)

This is a self-contained PHP port of the [TypeScript reference implementation](../src)
in `C:\provenance\src`, built specifically to run on shared hosting that offers
PHP/MySQL but no Node.js runtime (Network Solutions, in this case — confirmed
via its Configurations tab: PHP Manager, Softaculous, Database Manager present;
no Node.js Selector or Application Manager). See [`../README.md`](../README.md#php-vs-typescript-which-one-runs-where)
for how the two implementations relate to each other.

**Every crypto primitive here was cross-verified byte-for-byte against the
TS reference before being trusted** — see [`verification/`](verification/) for
the standalone scripts that proved it, and the "Verification" section below
for what they found (including one real bug: the TS reference's claim_hash
delimiter is an actual NUL byte, not the space its own code comment claims).

## Requirements

- PHP 8.1+ with `ext-sodium` and `ext-pdo_mysql` (both are core/bundled
  extensions since PHP 7.2/7.4 respectively — no separate install should be
  needed on any reasonably modern host)
- MySQL 5.7+ / MariaDB 10.2+ (needs `SIGNAL`, `ON DUPLICATE KEY UPDATE`,
  triggers — all long-standard)
- Composer (only for local dev — see [Deploying](#deploying) for why
  `vendor/` ships pre-built rather than needing Composer on the host)

## Local setup

```bash
composer install
cp .env.example .env   # if you add one; otherwise export the vars below directly
```

Environment variables (all optional locally — defaults assume `root`/no
password on `localhost:3306`; **do not rely on those defaults in production**):

| Variable | Purpose |
|---|---|
| `PROVENANCE_DB_HOST` | MySQL host |
| `PROVENANCE_DB_PORT` | MySQL port (default 3306) |
| `PROVENANCE_DB_NAME` | Database name |
| `PROVENANCE_DB_USER` | Database user |
| `PROVENANCE_DB_PASS` | Database password |

Run the dev server:

```bash
php -S localhost:8080 index.php
```

## Testing

```bash
composer install
# create a MySQL test database first, e.g.:
mysql -u root -e "CREATE DATABASE provenance_test CHARACTER SET utf8mb4;"

PROVENANCE_TEST_DB_HOST=127.0.0.1 PROVENANCE_TEST_DB_PORT=3306 \
PROVENANCE_TEST_DB_NAME=provenance_test PROVENANCE_TEST_DB_USER=root \
  php vendor/bin/phpunit
```

`tests/bootstrap.php` reads `PROVENANCE_TEST_DB_*` (falling back to
`127.0.0.1:3306`/`provenance_test`/`root`/no password) and points the app's
normal `PROVENANCE_DB_*` config at it, so the same `Db\Connection` code path
used in production runs in tests.

130 tests, 468 assertions, 93.37% line coverage (both re-measured
2026-08-18; see [../docs/CLAIMS_AUDIT.md](../docs/CLAIMS_AUDIT.md) for how
and why the previous 125/93.47% figures were stale) via
`php vendor/bin/phpunit --coverage-text` (requires Xdebug or PCOV —
neither ships with the stock PHP.net Windows build; see the project's main
session notes for how a coverage driver was obtained if you need to
reproduce this locally). Covers the same ground as the TS Vitest suite:
Wilson score against the 6 hand-computed reference values, signature
round-trip + malleability rejection, same-pubkey self-audit rejection
(note: this check is defeated by a second keypair — see
[../docs/CLAIMS_AUDIT.md](../docs/CLAIMS_AUDIT.md)) and duplicate-audit
rejection, staking/slashing at 0/1/2 overturns and at the 7-day window
boundary, Merkle/chain tamper detection, and — going beyond the TS suite,
because PHP's request-per-process model needed it — real concurrent-request
tests (`tests/Fuzz/ConcurrencyTest.php`, via `curl_multi` against a live
server) proving the double-slash and duplicate-audit races are actually
closed, not just theoretically closed.

## Verification

Before any application code was written, two things were proven, not
assumed, per the porting brief:

1. **Ed25519 via `ext-sodium`** (`verification/verify-ed25519.php`): same
   32-byte seed produces the same derived public key in both
   `@noble/curves` and libsodium; libsodium correctly verified a
   TS-produced signature; libsodium, signing the same message with the same
   key, produced a signature **byte-for-byte identical** to the TS one
   (Ed25519 signatures are deterministic per RFC 8032, so this is about as
   strong a confirmation as exists). Clean pass, no caveats.

2. **keccak256 via `kornrunner/keccak`** (`verification/verify-keccak.php`):
   the first attempt *mismatched* the TS reference. Root-caused rather than
   worked around: `kornrunner/keccak` was verified independently correct
   (matches a published test vector, matches a second unrelated JS library,
   matches `@noble/hashes` directly on arbitrary same-length input) — the
   actual bug was that `src/domain/claimHash.ts`'s `FIELD_DELIMITER`
   constant is a real `\x00` byte, not the space its own comment claims.
   This has been true since the TS implementation was first built; nothing
   ever caught it because every TS test is internally self-consistent
   (client and server always use the same function). `Domain\ClaimHash`
   here matches the TS reference's **actual** behavior — see the comment
   there and `verification/verify-ledger-chain.php`, which confirms the
   full leaf-hash → Merkle-root → chain-root pipeline reproduces a live
   TS server's output exactly, including for events with non-null
   `audit_ref`/boolean `audit_verdict`.

   Also verified in the same pass: PHP's `json_encode()` (used for
   `Ledger\Hash::computeLeafHash`, mirroring the TS reference's
   `JSON.stringify` over a field array) needs
   `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` to match JS output
   byte-for-byte — PHP escapes `/` and non-ASCII by default, JS does
   neither (`verification/verify-json-encoding.php`).

`verification/` is **not deployed** — `.htaccess` denies it outright, same
as `src/`, `vendor/`, and `tests/. It exists purely as an audit trail.

## Architecture

Mirrors the TS reference's module boundaries directly, one file at a time:

```
src/
  Crypto/     Keys (sodium), Encoding (keccak256/hex), Messages (signed-message builders)
  Domain/     ClaimHash
  Ledger/     Merkle, Hash (leaf/chain hashing), Store (PDO), Proof, Replay
  Scoring/    Wilson, ScoreStore (PDO)
  Protocol/   Finalize, Stakes (PDO), Slashing, RateLimit
  Db/         Connection (schema + PDO factory)
  Api/        Router, Validators, ApiException, Routes/*
index.php     Front controller (all requests routed through here)
.htaccess     mod_rewrite + directory hardening
```

### Where this deliberately differs from the TS reference

- **Storage**: MySQL/PDO instead of SQLite/better-sqlite3 (per the hosting
  constraint), with the same schema shape. One addition beyond the TS
  reference: `ledger_events` has a `UNIQUE KEY uniq_audit_ref_validator
  (audit_ref, validator_pubkey)` and `AuditRoute` wraps its critical section
  in a transaction with a row lock (`Store::lockOriginalClaim`, `SELECT
  ... FOR UPDATE`). The TS server is single-threaded with no `await` inside
  handlers, so a request is atomic by construction; PHP under Apache/PHP-FPM
  runs each request in its own process, so the double-slash and
  duplicate-audit races that can't happen in the TS server *can* happen
  here without an explicit DB-level guard. See the comments on
  `Db\Connection` and `Api\Routes\AuditRoute` for the full reasoning, and
  `tests/Fuzz/ConcurrencyTest.php` for the test that actually exercises it
  with real concurrent HTTP requests.
- **Routing layout**: no separate `public/` document root, because typical
  shared hosting can't be pointed at a subdirectory of what you upload —
  `index.php` sits at the same level as `src/`/`vendor/`, and `.htaccess`
  explicitly denies direct access to everything except itself.
- **Evidence-complexity length check**: `RateLimit::meetsEvidenceComplexity`
  uses `mb_strlen($text, 'UTF-8')` (Unicode codepoints), not PHP's raw
  `strlen()` (bytes). This matches the TS reference's `.length` (UTF-16
  code units) for the entire Basic Multilingual Plane; it only diverges for
  astral-plane characters (rare emoji, some CJK extensions) in claim text,
  which isn't part of any hashed/signed value — a narrow, documented
  difference rather than a silent one.

## Deploying

**Live at `https://basilwhite.com/provenance/`** as of this writing —
deployed via SFTP, database on the account's `provenance` MySQL database
(server `vuxmysql13`). `/submit`, `/audit`, `/verify`, and
`/validators/{pubkey}/score` all confirmed working against the real
production database with a real signed request (not just `/health`).

1. **Build for deploy**: `composer install --no-dev --optimize-autoloader`
   locally (skips PHPUnit and friends — they're dev-only and denied by
   `.htaccess` regardless, but no reason to upload them).
2. **Upload** the *contents* of `php-api/` — `index.php`, `.htaccess`,
   `src/`, `vendor/`, `composer.json`/`composer.lock`, `config.local.php`
   — to wherever `basilwhite.com/provenance/` resolves to (`htdocs/provenance/`
   on this account; SFTP `mkdir` is disabled account-wide here, so
   directories were created via a tiny token-gated PHP script using PHP's
   own `mkdir()`, triggered once over HTTP, then deleted — see git history
   for the pattern if this needs repeating). Do **not** upload `tests/`,
   `verification/`, `phpunit.xml.dist`, or `.phpunit.cache/`.
3. **Database**: `config.local.php` (gitignored, never committed — see
   `php-api/.gitignore`) `putenv()`s the real `PROVENANCE_DB_*` credentials
   and is conditionally `require`d at the top of `index.php` if present.
   Also explicitly denied by `.htaccess`'s `<FilesMatch>` block — **do not
   remove that line**; without it the file is directly web-fetchable (it
   was, briefly, during initial deployment, before being caught and fixed —
   confirmed it was never actually leaking its contents, since PHP was
   executing it normally rather than serving raw source, but it should
   never have been reachable at all).
4. **PHP version**: confirmed via PHP Manager — this hosting account runs
   **PHP 8.3**. The full test suite (all 125 tests, including the crypto
   cross-verification scripts) was re-run against a real PHP 8.3.32
   install and passed identically to PHP 8.2 — not assumed from the 8.2
   result, actually re-verified. `ext-sodium` and `ext-pdo_mysql` both
   load fine on 8.3 (confirmed via `php -m`).

### Two things only the real deployment surfaced

Neither was — or could have been — caught by local testing against a
root/superuser MySQL connection:

- **`CREATE TRIGGER` requires SUPER privilege** (or the
  `log_bin_trust_function_creators` server variable) when binary logging
  is on — standard on managed MySQL, which scoped app users never get.
  The append-only DB-level trigger from `Db\Connection` (see
  "Where this deliberately differs from the TS reference" above) failed
  outright against the real production database with MySQL error 1419.
  Fixed by making trigger creation best-effort: try it, and silently
  proceed without it if the privilege isn't there. The app-level guarantee
  (`Ledger\Store` never issues UPDATE/DELETE) still holds either way — the
  trigger was always a bonus, never the only safeguard.
- **The `.htaccess` catch-all rewrite didn't fire on this host.** The
  `RewriteCond %{REQUEST_FILENAME} !-f` / `!-d` pattern — completely
  standard, and how most PHP front-controller `.htaccess` files are
  written — silently never matched here, even though `mod_rewrite` was
  confirmed active (a separate, unconditional `RewriteRule` in the same
  file worked fine). Root cause not fully pinned down; worked around by
  guarding on `%{REQUEST_URI}` instead of file-existence checks, which is
  more portable across the range of ways `REQUEST_FILENAME` gets resolved
  behind different proxy/CGI setups. If this app is ever redeployed to a
  *different* host, re-verify the rewrite actually reaches `index.php`
  before assuming it works.

## API reference

Same endpoints, same request/response shapes as the TS reference — see
[`../openapi.yaml`](../openapi.yaml). Only difference: base path is
`https://basilwhite.com/provenance` instead of `http://localhost:3000`.
