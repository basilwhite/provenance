<?php

declare(strict_types=1);

/**
 * Build-time generator for src/Api/LandingPage.php — the HTML served at
 * GET / (see Api\Router). Runs at build/dev time only (needs symfony/yaml,
 * a require-dev dependency never shipped to production); output is a
 * plain PHP string constant, so production never parses YAML or carries
 * this dependency. Re-run this after any change to openapi.yaml or to the
 * field-format constants in Api\Validators / Protocol\RateLimit:
 *
 *   php scripts/generate-landing-page.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Domain\ClaimHash;
use Provenance\Ledger\Merkle;
use Provenance\Protocol\RateLimit;
use Symfony\Component\Yaml\Yaml;

// ---------------------------------------------------------------------
// 1. Parse openapi.yaml — the endpoint list, methods, and descriptions
//    below come from here, not from memory.
// ---------------------------------------------------------------------

$openapiPath = __DIR__ . '/../../openapi.yaml';
$spec = Yaml::parseFile($openapiPath);
$baseUrl = 'https://basilwhite.com/provenance';

$endpoints = [];
foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $method => $def) {
        if (!in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            continue;
        }
        $endpoints[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'summary' => $def['summary'] ?? '',
        ];
    }
}

// ---------------------------------------------------------------------
// 2. Field-format constraints — pulled from the actual validator source
//    via reflection, not restated from memory.
// ---------------------------------------------------------------------

function hexCharsFromPattern(string $pattern): int
{
    if (!preg_match('/\{(\d+)\}/', $pattern, $m)) {
        throw new RuntimeException("could not extract hex length from pattern: $pattern");
    }
    return (int) $m[1];
}

$validatorsRef = new ReflectionClass(\Provenance\Api\Validators::class);
$pubkeyHexChars = hexCharsFromPattern($validatorsRef->getConstant('PUBKEY_HEX_RE'));
$signatureHexChars = hexCharsFromPattern($validatorsRef->getConstant('SIGNATURE_HEX_RE'));
$hashHexChars = hexCharsFromPattern($validatorsRef->getConstant('HASH_HEX_RE'));

$fieldFormats = [
    'pubkey_bytes' => intdiv($pubkeyHexChars, 2),
    'pubkey_hex_chars' => $pubkeyHexChars,
    'signature_bytes' => intdiv($signatureHexChars, 2),
    'signature_hex_chars' => $signatureHexChars,
    'hash_bytes' => intdiv($hashHexChars, 2),
    'hash_hex_chars' => $hashHexChars,
    'min_claim_text_chars' => RateLimit::MIN_CLAIM_TEXT_CHARS,
];

// ---------------------------------------------------------------------
// 3. Real, cryptographically valid example data — generated with the
//    actual production classes (Keys, ClaimHash, Messages, Merkle), so
//    every example on the page is genuinely runnable, not illustrative
//    placeholder text. One consistent narrative: submitter submits a
//    claim, two auditors confirm/overturn it, a batch example, a key
//    rotation example.
// ---------------------------------------------------------------------

$submitter = Keys::generateKeyPair();
$auditor1 = Keys::generateKeyPair();
$auditor2 = Keys::generateKeyPair();
$oldKey = Keys::generateKeyPair();
$newKey = Keys::generateKeyPair();

$claimText =
    'On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out ' .
    'SWE-bench-lite split (300 tasks) using the standard agentic scaffold with a 50-step ' .
    'budget. The run resolved 217/300 tasks (72.3% pass@1), matching the previously ' .
    'reported internal benchmark within 0.4 percentage points. Full transcripts, the ' .
    'evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are ' .
    'attached at the evidence URI. No tasks were excluded or retried beyond the harness\'s ' .
    'standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 minutes. This ' .
    'claim asserts the reported pass rate is accurate and reproducible from the attached artifacts.';
$evidenceUri = 'https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json';
$timestamp = 1772000000000;

$claimHash = ClaimHash::compute($claimText, $evidenceUri, $timestamp);
$submitSig = Encoding::bytesToHex(Keys::sign(Messages::claimTimestampMessage($claimHash, $timestamp), $submitter['privateKey']));

$auditTimestamp1 = $timestamp + 60_000;
$auditSig1 = Encoding::bytesToHex(Keys::sign(Messages::claimTimestampMessage($claimHash, $auditTimestamp1), $auditor1['privateKey']));

$auditTimestamp2 = $timestamp + 120_000;
$auditSig2 = Encoding::bytesToHex(Keys::sign(Messages::claimTimestampMessage($claimHash, $auditTimestamp2), $auditor2['privateKey']));

// Batch example: 2 short-but-valid claims from the same submitter.
$batchTimestamp = $timestamp + 200_000;
$batchClaims = [];
foreach ([0, 1] as $i) {
    $bClaimText = $claimText . " (batch item {$i})";
    $bEvidenceUri = "https://evidence.example.org/runs/batch-{$i}.json";
    $bTimestamp = $batchTimestamp + $i;
    $bClaimHash = ClaimHash::compute($bClaimText, $bEvidenceUri, $bTimestamp);
    $bSig = Encoding::bytesToHex(Keys::sign(Messages::claimTimestampMessage($bClaimHash, $bTimestamp), $submitter['privateKey']));
    $batchClaims[] = [
        'claim_text' => $bClaimText,
        'evidence_uri' => $bEvidenceUri,
        'timestamp' => $bTimestamp,
        'signature' => $bSig,
        'claim_hash' => $bClaimHash,
    ];
}
$batchRoot = Merkle::buildMerkleTree(array_column($batchClaims, 'claim_hash'))['root'];
$batchSig = Encoding::bytesToHex(Keys::sign(Messages::batchMessage($batchRoot, $batchTimestamp), $submitter['privateKey']));

$rotationSig = Encoding::bytesToHex(Keys::sign(
    Messages::rotationMessage(Encoding::bytesToHex($oldKey['publicKey']), Encoding::bytesToHex($newKey['publicKey'])),
    $oldKey['privateKey'],
));

$submitterPub = Encoding::bytesToHex($submitter['publicKey']);
$auditor1Pub = Encoding::bytesToHex($auditor1['publicKey']);
$auditor2Pub = Encoding::bytesToHex($auditor2['publicKey']);
$oldPub = Encoding::bytesToHex($oldKey['publicKey']);
$newPub = Encoding::bytesToHex($newKey['publicKey']);

// ---------------------------------------------------------------------
// 4. Build the curl examples per endpoint (real values from above).
// ---------------------------------------------------------------------

function jsonForCurl(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

$examples = [
    'GET /health' => "curl -s {$baseUrl}/health",
    'POST /submit' => "curl -s -X POST {$baseUrl}/submit \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . jsonForCurl([
        'claim_text' => $claimText,
        'evidence_uri' => $evidenceUri,
        'timestamp' => $timestamp,
        'validator_pubkey' => $submitterPub,
        'signature' => $submitSig,
    ]) . "'",
    'POST /audit' => "curl -s -X POST {$baseUrl}/audit \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . jsonForCurl([
        'claim_hash' => $claimHash,
        'audit_verdict' => true,
        'timestamp' => $auditTimestamp1,
        'validator_pubkey' => $auditor1Pub,
        'signature' => $auditSig1,
    ]) . "'",
    'POST /submit/batch' => "curl -s -X POST {$baseUrl}/submit/batch \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . jsonForCurl([
        'validator_pubkey' => $submitterPub,
        'timestamp' => $batchTimestamp,
        'batch_signature' => $batchSig,
        'claims' => array_map(
            static fn(array $c) => [
                'claim_text' => $c['claim_text'],
                'evidence_uri' => $c['evidence_uri'],
                'timestamp' => $c['timestamp'],
                'signature' => $c['signature'],
            ],
            $batchClaims,
        ),
    ]) . "'",
    'POST /keys/rotate' => "curl -s -X POST {$baseUrl}/keys/rotate \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . jsonForCurl([
        'old_pubkey' => $oldPub,
        'new_pubkey' => $newPub,
        'rotation_signature' => $rotationSig,
    ]) . "'",
    'GET /verify/{claim_hash}' => "curl -s {$baseUrl}/verify/{$claimHash}",
    'GET /validators/{pubkey}/score' => "curl -s {$baseUrl}/validators/{$submitterPub}/score",
    'GET /validators/{pubkey}/events' => "curl -s {$baseUrl}/validators/{$submitterPub}/events",
];

// ---------------------------------------------------------------------
// 5. Render HTML.
// ---------------------------------------------------------------------

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$rows = '';
foreach ($endpoints as $e) {
    $key = "{$e['method']} {$e['path']}";
    $example = $examples[$key] ?? null;
    $anchor = 'ep-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
    $rows .= "<tr><td><a href=\"#{$anchor}\">{$e['method']}</a></td><td><code>" . h($e['path']) . "</code></td><td>" . h($e['summary']) . "</td></tr>\n";
}

$sections = '';
foreach ($endpoints as $e) {
    $key = "{$e['method']} {$e['path']}";
    $example = $examples[$key] ?? null;
    $anchor = 'ep-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
    $sections .= "<h3 id=\"{$anchor}\"><span class=\"method method-" . strtolower($e['method']) . "\">{$e['method']}</span> <code>" . h($e['path']) . "</code></h3>\n";
    $sections .= "<p>" . h($e['summary']) . "</p>\n";
    if ($example !== null) {
        $sections .= "<pre><code>" . h($example) . "</code></pre>\n";
    }
}

$endpointListJson = json_encode(array_map(static fn($e) => "{$e['method']} {$e['path']}", $endpoints));

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Provenance API</title>
<style>
  :root {
    --bg: #0b0e14;
    --bg-panel: #131722;
    --border: #232838;
    --text: #d7dce5;
    --text-dim: #8b93a7;
    --accent: #6ee7b7;
    --accent-dim: #2f7a5c;
    --mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font-family: var(--sans); line-height: 1.6;
  }
  .wrap { max-width: 880px; margin: 0 auto; padding: 48px 24px 96px; }
  header { margin-bottom: 40px; }
  h1 { font-size: 2.1rem; margin: 0 0 8px; color: #fff; letter-spacing: -0.02em; }
  .tagline { color: var(--text-dim); font-size: 1.05rem; margin: 0 0 16px; }
  .pitch {
    border-left: 3px solid var(--accent); padding: 10px 16px;
    background: var(--bg-panel); border-radius: 0 6px 6px 0; color: var(--text);
  }
  h2 { font-size: 1.3rem; color: #fff; margin: 40px 0 12px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
  h3 { font-size: 1.05rem; margin: 28px 0 6px; color: #fff; }
  p { color: var(--text); margin: 8px 0; }
  a { color: var(--accent); text-decoration: none; }
  a:hover { text-decoration: underline; }
  code { font-family: var(--mono); background: var(--bg-panel); padding: 1px 6px; border-radius: 4px; font-size: 0.9em; color: var(--accent); }
  pre {
    background: var(--bg-panel); border: 1px solid var(--border); border-radius: 8px;
    padding: 14px 16px; overflow-x: auto; margin: 8px 0 20px;
  }
  pre code { background: none; padding: 0; color: var(--text); font-size: 0.85rem; white-space: pre; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 0.92rem; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
  th { color: var(--text-dim); font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
  .flow { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
  .flow-step {
    background: var(--bg-panel); border: 1px solid var(--border); border-radius: 6px;
    padding: 10px 14px; flex: 1 1 150px; font-size: 0.88rem;
  }
  .flow-step b { color: var(--accent); display: block; margin-bottom: 4px; }
  .method { font-family: var(--mono); font-size: 0.78rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
  .method-get { background: rgba(110,231,183,0.15); color: var(--accent); }
  .method-post { background: rgba(129,161,255,0.15); color: #81a1ff; }
  ul.fmt li { margin: 6px 0; }
  ul.fmt code { color: #fff; }
  footer { margin-top: 56px; padding-top: 20px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 0.88rem; }
  footer a { margin-right: 18px; }
</style>
</head>
<body>
<div class="wrap">

<header>
  <h1>Provenance</h1>
  <p class="tagline">A ledger-backed AI validator reputation system.</p>
  <p class="pitch"><b>Don't Trust, Verify.</b> Every claim, audit, and stake slash is a signed,
  append-only ledger event chained by Merkle roots. Nothing here asks you to trust a
  dashboard — pull the raw events and recompute the score yourself.</p>
</header>

<section>
  <h2>How it works</h2>
  <div class="flow">
    <div class="flow-step"><b>1. Submit</b>A validator signs and submits a claim about AI system behavior.</div>
    <div class="flow-step"><b>2. Audit</b>Other validators confirm or overturn it &mdash; self-audits are rejected.</div>
    <div class="flow-step"><b>3. Finalize</b>Once a claim has &ge;2 audits, the submitter's score recomputes.</div>
    <div class="flow-step"><b>4. Score</b>A Wilson-lower-bound estimate over all finalized claims &mdash; not a raw ratio.</div>
    <div class="flow-step"><b>5. Verify</b>Anyone recomputes the score offline from raw signed events. No trust required.</div>
  </div>
</section>

<section>
  <h2>Endpoints</h2>
  <table>
    <thead><tr><th>Method</th><th>Path</th><th>Description</th></tr></thead>
    <tbody>
{$rows}    </tbody>
  </table>
</section>

<section>
  <h2>Reference &amp; examples</h2>
  <p>Every example below uses real, cryptographically valid data &mdash; generated with the
  same Ed25519/keccak256 code the API itself runs. Run them in order (submit &rarr; audit
  &rarr; verify) against this live API and they'll actually work.</p>
{$sections}
</section>

<section>
  <h2>Field formats</h2>
  <ul class="fmt">
    <li><code>validator_pubkey</code>, <code>claim_hash</code>, <code>old_pubkey</code>, <code>new_pubkey</code> &mdash;
      0x-prefixed, {$fieldFormats['pubkey_bytes']}-byte ({$fieldFormats['pubkey_hex_chars']} hex char) values.</li>
    <li><code>signature</code>, <code>batch_signature</code>, <code>rotation_signature</code> &mdash;
      0x-prefixed, {$fieldFormats['signature_bytes']}-byte ({$fieldFormats['signature_hex_chars']} hex char) Ed25519 signatures.</li>
    <li><code>timestamp</code> &mdash; Unix milliseconds (not seconds).</li>
    <li><code>claim_text</code> &mdash; must be &ge; {$fieldFormats['min_claim_text_chars']} characters.</li>
  </ul>
</section>

<section>
  <h2>Trust justification</h2>
  <p>Immutability (append-only, Merkle-chained, no update/delete path), accountability
  (a public key is a validator's lifelong identity), and transparency (offline
  verification, no trust required) &mdash; see the
  <a href="https://github.com/basilwhite/provenance#trust-justification">full trust justification</a>
  for the complete argument with worked examples.</p>
  <p><b>Known gap, stated plainly:</b> a stake-and-slash mechanism exists on every claim,
  but current stake is a free, auto-provisioned simulation with no real cost to acquire
  or replace &mdash; it does not yet deter a validator from re-keying after a slash. See
  <a href="https://github.com/basilwhite/provenance/blob/main/docs/CURRENT_STATE.md">docs/CURRENT_STATE.md</a>
  for the full accounting of what's solved and what isn't.</p>
</section>

<footer>
  <a href="https://github.com/basilwhite/provenance">GitHub repo</a>
  <a href="https://github.com/basilwhite/provenance/blob/main/openapi.yaml">openapi.yaml</a>
  <a href="https://github.com/basilwhite/provenance#trust-justification">Trust justification</a>
</footer>

</div>
</body>
</html>
HTML;

// ---------------------------------------------------------------------
// 6. Write src/Api/LandingPage.php
// ---------------------------------------------------------------------

$phpSource = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Provenance\\Api;\n\n"
    . "/**\n * GENERATED FILE - do not edit by hand.\n"
    . " * Regenerate with: php scripts/generate-landing-page.php\n"
    . " * Source: ../../openapi.yaml + Api\\Validators + Protocol\\RateLimit\n"
    . " */\n"
    . "final class LandingPage\n{\n"
    . "    public const ENDPOINT_PATHS = " . var_export(array_map(static fn($e) => "{$e['method']} {$e['path']}", $endpoints), true) . ";\n\n"
    . "    public const HTML = <<<'HTML'\n"
    . $html . "\n"
    . "HTML;\n"
    . "}\n";

file_put_contents(__DIR__ . '/../src/Api/LandingPage.php', $phpSource);

echo "Generated src/Api/LandingPage.php (" . strlen($html) . " bytes of HTML, " . count($endpoints) . " endpoints)\n";
