<?php
require __DIR__ . '/../vendor/autoload.php';

use kornrunner\Keccak;

$claimText =
    "On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite " .
    "split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved " .
    "217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 " .
    "percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw " .
    "per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried " .
    "beyond the harness's standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 " .
    "minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached " .
    "artifacts.";
$evidenceUri = "https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json";
$timestamp = 1772000000000;

// IMPORTANT: src/domain/claimHash.ts's FIELD_DELIMITER is actually "\x00" (a NUL byte),
// NOT the space its own comment claims — confirmed by direct inspection of the file's raw
// bytes plus cross-checking against the live claim_hash the TS server actually returned.
// This preserves wire compatibility with the real (buggy-comment, correct-behavior) reference.
$preimage = $claimText . "\x00" . $evidenceUri . "\x00" . $timestamp;

$hash = Keccak::hash($preimage, 256);
$claimHash = '0x' . $hash;

$expected = '0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72';

echo "Computed: $claimHash\n";
echo "Expected: $expected\n";

if ($claimHash === $expected) {
    echo "MATCH: keccak256 via kornrunner/keccak IS compatible with actual TS reference behavior (NUL-byte delimiter).\n";
    exit(0);
} else {
    echo "MISMATCH. DO NOT PROCEED.\n";
    exit(1);
}
