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

125 tests, 93.47% line coverage (`php vendor/bin/phpunit --coverage-text`,
requires Xdebug or PCOV — neither ships with the stock PHP.net Windows
build; see the project's main session notes for how a coverage driver was
obtained if you need to reproduce this locally). Covers the same ground as
the TS Vitest suite: Wilson score against the 6 hand-computed reference
values, signature round-trip + malleability rejection, self-audit/duplicate-
audit rejection, staking/slashing at 0/1/2 overturns and at the 7-day window
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

Target: `https://basilwhite.com/provenance/`, uploaded via the existing
FTP/File Manager workflow (this hosting account has no SSH/Composer access
confirmed available, so `vendor/` is built locally and uploaded as-is
rather than run via `composer install` on the server).

1. **Build for deploy**: `composer install --no-dev --optimize-autoloader`
   locally (skips PHPUnit and friends — they're dev-only and denied by
   `.htaccess` regardless, but no reason to upload them).
2. **Upload** the *contents* of `php-api/` — `index.php`, `.htaccess`,
   `src/`, `vendor/`, `composer.json`/`composer.lock` — to
   `public_html/provenance/` (or wherever `basilwhite.com/provenance/`
   resolves to on this account). Do **not** upload `tests/`,
   `verification/`, `phpunit.xml.dist`, or `.phpunit.cache/`.
3. **Database**: create/confirm a MySQL database via Database Manager (the
   account already has one provisioned, 300MB/7.5GB quota per the account
   snapshot), then set `PROVENANCE_DB_*` for the live app. Shared hosting
   typically has no way to set process environment variables for PHP
   directly — the practical option is a small `config.local.php` (gitignored,
   never committed) that `putenv()`s the real credentials, included at the
   very top of `index.php`, OR setting them via `.htaccess`
   `SetEnv`/`php_value` directives if the host's Apache config allows
   `AllowOverride` for that. **This repo does not hardcode production DB
   credentials anywhere** — they need to be supplied at deploy time via
   whichever of those mechanisms this specific account supports.
4. **PHP version**: confirmed present are PHP Manager and Softaculous;
   the exact PHP version wasn't visible in the account snapshot captured
   (only the hosting overview page was saved, not the PHP Manager
   subpage) — needs a quick check before the first deploy. `ext-sodium` has
   been a default-enabled core extension since PHP 7.2, so unless this
   account is running something startlingly old, it should already be
   available with no separate install step; worth a 30-second confirmation
   in PHP Manager rather than assuming.

## API reference

Same endpoints, same request/response shapes as the TS reference — see
[`../openapi.yaml`](../openapi.yaml). Only difference: base path is
`https://basilwhite.com/provenance` instead of `http://localhost:3000`.
