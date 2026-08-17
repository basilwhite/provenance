<?php

declare(strict_types=1);

namespace Provenance\Api;

/**
 * GENERATED FILE - do not edit by hand.
 * Regenerate with: php scripts/generate-landing-page.php
 * Source: ../../openapi.yaml + Api\Validators + Protocol\RateLimit
 */
final class LandingPage
{
    public const ENDPOINT_PATHS = array (
  0 => 'GET /health',
  1 => 'POST /submit',
  2 => 'POST /audit',
  3 => 'POST /submit/batch',
  4 => 'POST /keys/rotate',
  5 => 'GET /verify/{claim_hash}',
  6 => 'GET /validators/{pubkey}/score',
  7 => 'GET /validators/{pubkey}/events',
);

    public const HTML = <<<'HTML'
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
<tr><td><a href="#ep-GET-health">GET</a></td><td><code>/health</code></td><td>Liveness check</td></tr>
<tr><td><a href="#ep-POST-submit">POST</a></td><td><code>/submit</code></td><td>Submit a new claim (F3.1)</td></tr>
<tr><td><a href="#ep-POST-audit">POST</a></td><td><code>/audit</code></td><td>Audit an existing claim, confirming or overturning it (F3.2)</td></tr>
<tr><td><a href="#ep-POST-submit-batch">POST</a></td><td><code>/submit/batch</code></td><td>Submit up to 50 claims from one validator as a single ledger event (F5.3)</td></tr>
<tr><td><a href="#ep-POST-keys-rotate">POST</a></td><td><code>/keys/rotate</code></td><td>Rotate a validator&#039;s Ed25519 key, preserving reputation continuity (F1.2)</td></tr>
<tr><td><a href="#ep-GET-verify-claim-hash-">GET</a></td><td><code>/verify/{claim_hash}</code></td><td>Public verification of one claim plus its submitter&#039;s full track record (F6.1)</td></tr>
<tr><td><a href="#ep-GET-validators-pubkey-score">GET</a></td><td><code>/validators/{pubkey}/score</code></td><td>Current Wilson score for a validator (F4.2)</td></tr>
<tr><td><a href="#ep-GET-validators-pubkey-events">GET</a></td><td><code>/validators/{pubkey}/events</code></td><td>Raw event history for a validator, for the offline verifier CLI (F6.2)</td></tr>
    </tbody>
  </table>
</section>

<section>
  <h2>Reference &amp; examples</h2>
  <p>Every example below uses real, cryptographically valid data &mdash; generated with the
  same Ed25519/keccak256 code the API itself runs. Run them in order (submit &rarr; audit
  &rarr; verify) against this live API and they'll actually work.</p>
<h3 id="ep-GET-health"><span class="method method-get">GET</span> <code>/health</code></h3>
<p>Liveness check</p>
<pre><code>curl -s https://basilwhite.com/provenance/health</code></pre>
<h3 id="ep-POST-submit"><span class="method method-post">POST</span> <code>/submit</code></h3>
<p>Submit a new claim (F3.1)</p>
<pre><code>curl -s -X POST https://basilwhite.com/provenance/submit \
  -H &quot;Content-Type: application/json&quot; \
  -d &#039;{
    &quot;claim_text&quot;: &quot;On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved 217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried beyond the harness&#039;s standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached artifacts.&quot;,
    &quot;evidence_uri&quot;: &quot;https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json&quot;,
    &quot;timestamp&quot;: 1772000000000,
    &quot;validator_pubkey&quot;: &quot;0x1990d17fab516712f80565fdbd4bf18571b3e6246ddcc8c896bc370a00043f9e&quot;,
    &quot;signature&quot;: &quot;0x1d3b1ef21ca71f6e8c521ce238eea748736a311523246f1f6d11c9652b3dd2dc88103c26741f12aeaef4e027752346a1b18746eec2afa281ebcfecbe88dc7a0a&quot;
}&#039;</code></pre>
<h3 id="ep-POST-audit"><span class="method method-post">POST</span> <code>/audit</code></h3>
<p>Audit an existing claim, confirming or overturning it (F3.2)</p>
<pre><code>curl -s -X POST https://basilwhite.com/provenance/audit \
  -H &quot;Content-Type: application/json&quot; \
  -d &#039;{
    &quot;claim_hash&quot;: &quot;0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72&quot;,
    &quot;audit_verdict&quot;: true,
    &quot;timestamp&quot;: 1772000060000,
    &quot;validator_pubkey&quot;: &quot;0xcef9edd1d1be9d8398b3ae282b2cbe1f97e1f63ce4601f3cc23c66e86ce18c04&quot;,
    &quot;signature&quot;: &quot;0x6e666a07d6abc835b3f067172949cc2b7afa38676820413c2ede94e101c64e65883280e7766011f7fb750e0590bf87881fe0e1cb7e91cc75b9a086fdbf91440f&quot;
}&#039;</code></pre>
<h3 id="ep-POST-submit-batch"><span class="method method-post">POST</span> <code>/submit/batch</code></h3>
<p>Submit up to 50 claims from one validator as a single ledger event (F5.3)</p>
<pre><code>curl -s -X POST https://basilwhite.com/provenance/submit/batch \
  -H &quot;Content-Type: application/json&quot; \
  -d &#039;{
    &quot;validator_pubkey&quot;: &quot;0x1990d17fab516712f80565fdbd4bf18571b3e6246ddcc8c896bc370a00043f9e&quot;,
    &quot;timestamp&quot;: 1772000200000,
    &quot;batch_signature&quot;: &quot;0x0e0c6c17d0d8a8ef06febad4909609fdaabb0c81ced32302b51791c9b65d82dc5fefb0dacca169ca5c26d07d6fe299f214c4a424939b94b5ef992d288e961e00&quot;,
    &quot;claims&quot;: [
        {
            &quot;claim_text&quot;: &quot;On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved 217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried beyond the harness&#039;s standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached artifacts. (batch item 0)&quot;,
            &quot;evidence_uri&quot;: &quot;https://evidence.example.org/runs/batch-0.json&quot;,
            &quot;timestamp&quot;: 1772000200000,
            &quot;signature&quot;: &quot;0xff05072385ca6564005f9751e6fc8dc97148ca3e9ee3ff85582fd50aa9fb2a8a75d7c176b8646db1cfa31a14bd63942bbbf263702f39c9eb46dd59ffe7440206&quot;
        },
        {
            &quot;claim_text&quot;: &quot;On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved 217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried beyond the harness&#039;s standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached artifacts. (batch item 1)&quot;,
            &quot;evidence_uri&quot;: &quot;https://evidence.example.org/runs/batch-1.json&quot;,
            &quot;timestamp&quot;: 1772000200001,
            &quot;signature&quot;: &quot;0x408fd9c6e6b482f3ae7619482f3c091aa58dfec78c341d0d1ae582addef14140895f4993682cc19d80fab257e1845423e2fbaec6dfaeb18f5c78c79a242ff803&quot;
        }
    ]
}&#039;</code></pre>
<h3 id="ep-POST-keys-rotate"><span class="method method-post">POST</span> <code>/keys/rotate</code></h3>
<p>Rotate a validator&#039;s Ed25519 key, preserving reputation continuity (F1.2)</p>
<pre><code>curl -s -X POST https://basilwhite.com/provenance/keys/rotate \
  -H &quot;Content-Type: application/json&quot; \
  -d &#039;{
    &quot;old_pubkey&quot;: &quot;0x05720cfb7a52a99db0977ed6cc447d02a2672b298bb6ff40424d55d24808a74b&quot;,
    &quot;new_pubkey&quot;: &quot;0x4313457b30ec42c0db9e9b9c3cd557e0c09a2de07c57b51101fcb9d27181c62c&quot;,
    &quot;rotation_signature&quot;: &quot;0x2e05af30da907fb534762beb3c02a041a3abdd3d4236c0977363b0e61d13b69ea3c6aaefb3a808518a5a3254318916902c5720487da533ff3a9cbf0be167980d&quot;
}&#039;</code></pre>
<h3 id="ep-GET-verify-claim-hash-"><span class="method method-get">GET</span> <code>/verify/{claim_hash}</code></h3>
<p>Public verification of one claim plus its submitter&#039;s full track record (F6.1)</p>
<pre><code>curl -s https://basilwhite.com/provenance/verify/0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72</code></pre>
<h3 id="ep-GET-validators-pubkey-score"><span class="method method-get">GET</span> <code>/validators/{pubkey}/score</code></h3>
<p>Current Wilson score for a validator (F4.2)</p>
<pre><code>curl -s https://basilwhite.com/provenance/validators/0x1990d17fab516712f80565fdbd4bf18571b3e6246ddcc8c896bc370a00043f9e/score</code></pre>
<h3 id="ep-GET-validators-pubkey-events"><span class="method method-get">GET</span> <code>/validators/{pubkey}/events</code></h3>
<p>Raw event history for a validator, for the offline verifier CLI (F6.2)</p>
<pre><code>curl -s https://basilwhite.com/provenance/validators/0x1990d17fab516712f80565fdbd4bf18571b3e6246ddcc8c896bc370a00043f9e/events</code></pre>

</section>

<section>
  <h2>Field formats</h2>
  <ul class="fmt">
    <li><code>validator_pubkey</code>, <code>claim_hash</code>, <code>old_pubkey</code>, <code>new_pubkey</code> &mdash;
      0x-prefixed, 32-byte (64 hex char) values.</li>
    <li><code>signature</code>, <code>batch_signature</code>, <code>rotation_signature</code> &mdash;
      0x-prefixed, 64-byte (128 hex char) Ed25519 signatures.</li>
    <li><code>timestamp</code> &mdash; Unix milliseconds (not seconds).</li>
    <li><code>claim_text</code> &mdash; must be &ge; 500 characters.</li>
  </ul>
</section>

<section>
  <h2>Trust justification</h2>
  <p>Immutability (append-only, Merkle-chained, no update/delete path), accountability
  (a public key is a validator's lifelong identity), economic disincentives (staking and
  slashing make bad claims costly), and transparency (offline verification, no trust
  required) &mdash; see the
  <a href="https://github.com/basilwhite/provenance#trust-justification">full trust justification</a>
  for the complete argument with worked examples.</p>
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
}
