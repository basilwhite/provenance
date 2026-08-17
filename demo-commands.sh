#!/usr/bin/env bash
# Companion to DEMO_VIDEO_SCRIPT.md — run this in a bash-compatible shell
# (git bash / WSL) with the server already running (`npm start` in another
# terminal). Each step pauses so you can narrate before hitting Enter.
#
# CORRECTED: claim_hash values below use the real FIELD_DELIMITER (a NUL
# byte, \x00) from src/domain/claimHash.ts, not the space its own comment
# implies. The previous version of this file used the wrong delimiter and
# its signatures would fail server-side verification. This version's
# claim1 hash (0xd3c4cede...) matches the value already published in your
# README, which came from an actual live run.

set -e
BASE="http://localhost:3000"
pause() { read -rp "-- press Enter to run: $1 --" _; }

SUBMITTER="0xb00c1697cbda5d91c38e36b1af641de665d95488cb7333716ddc05d5a02f7e7d"
CLAIM1_HASH="0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72"
CLAIM2_HASH="0x5aac824285a057b70698950bcf0cc1f461b99c7906ef52b7fa994d1fa66bfa64"

pause "1. submit claim 1"
curl -s -X POST "$BASE/submit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_text": "On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved 217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried beyond the harness'\''s standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached artifacts.",
    "evidence_uri": "https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json",
    "timestamp": 1772000000000,
    "validator_pubkey": "'"$SUBMITTER"'",
    "signature": "0xe1b4ccae0d0049f02eb883e58a084153c73d94daa7c08a54639ddd38a393f97d6ae8874b503b5f31646b1e6daf32bb4322c8d66e7f0093575b621226bd0af70e"
  }' | tee /tmp/last.json; echo

pause "2. audit confirm #1 on claim 1"
curl -s -X POST "$BASE/audit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "'"$CLAIM1_HASH"'",
    "audit_verdict": true,
    "timestamp": 1772000060000,
    "validator_pubkey": "0x706f38a17b7a41c890dfe206e7b9b2e7c68818770fc8257d69b43528143047b7",
    "signature": "0x6a2cfb936243e9545205d1938a4e753f8a64a9da6ff9fbc8a0a1c3d380fdcb0db9848b335375afc46a50986a13392731265f51e3ccf33dc128ea7a87fd54eb00"
  }'; echo

pause "3. audit confirm #2 on claim 1 -> score should recompute"
curl -s -X POST "$BASE/audit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "'"$CLAIM1_HASH"'",
    "audit_verdict": true,
    "timestamp": 1772000120000,
    "validator_pubkey": "0xb23bfe6f0f1cb443139886765fcaa1dd68d78edf552719ecf1f513f7e1d9c66d",
    "signature": "0x3fe59b9d393e5bc68f4741ea90922bd2a0e6a13ac5b8fe407953c6a921d8342590236eca4997bbdcbf8fbdc64d00485c785ed21771f5f0bff1ca33a670dd0e07"
  }'; echo

pause "4. check score -> expect n=2, confirmations=2, overturns=0, score=0.5"
curl -s "$BASE/validators/$SUBMITTER/score"; echo

pause "5. submit claim 2"
curl -s -X POST "$BASE/submit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_text": "On 2026-03-04, the same checkpoint gpt-audit-7b was evaluated against a second held-out split, HumanEval-plus (250 tasks), using the identical agentic scaffold and a 50-step budget. The run resolved 231/250 tasks (92.4% pass@1). Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried beyond the harness'\''s standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 33 minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached artifacts, and that no test-set contamination occurred between the harness commit and the evaluation run.",
    "evidence_uri": "https://evidence.example.org/runs/gpt-audit-7b-humaneval-plus-2026-03-04.json",
    "timestamp": 1772100000000,
    "validator_pubkey": "'"$SUBMITTER"'",
    "signature": "0xd7b37559a4803cdcdb0f474f55c24a41b2bea07ed604938a3597c7e125d0e372ef75dbc277805621057982598b365231098575d591d3d03a422613d08d194103"
  }'; echo

pause "6. audit confirm #3 on claim 2"
curl -s -X POST "$BASE/audit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "'"$CLAIM2_HASH"'",
    "audit_verdict": true,
    "timestamp": 1772100060000,
    "validator_pubkey": "0x6bfb5231efc6707a25bc4427ff529f229d7404efddbd3b010f2642b97c566bda",
    "signature": "0x3513c6237d4697ed554995db5c1089dd5cb25f79cd04df2fad0001085a52ed8716f03be7a68f792ba5b3dbb50b258c4000962cc2c98afe6e5bc72ca6e4a5f60b"
  }'; echo

pause "7. audit confirm #4 on claim 2"
curl -s -X POST "$BASE/audit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "'"$CLAIM2_HASH"'",
    "audit_verdict": true,
    "timestamp": 1772100120000,
    "validator_pubkey": "0xce0e3bf3d8abf02cdb3c8af64749db5529114af33b5cfa1653034917e49cd5fa",
    "signature": "0x7bbf5ab67fe308573213734311d6d4ab93d07a9082dc01502a2ca3235b914dfaf9d4091cf2810bee1a52dda36ca863b87efc61151fd39109d41dbdcb3e740504"
  }'; echo

pause "8. check score -> expect n=4, confirmations=4, overturns=0, score=0.5 (still under n>=5 floor)"
curl -s "$BASE/validators/$SUBMITTER/score"; echo

pause "9. AUDIT OVERTURN on claim 2 -> crosses n=5"
curl -s -X POST "$BASE/audit" \
  -H "Content-Type: application/json" \
  -d '{
    "claim_hash": "'"$CLAIM2_HASH"'",
    "audit_verdict": false,
    "timestamp": 1772100180000,
    "validator_pubkey": "0x9cfa73e0af92c734b7070158c4994a829becbfcc21ad0145309e74ba3dfa2762",
    "signature": "0x0c44da051f649ae89e7be27fe3b2e95ecccfe0076632b9deef4bd5db59c3428210842af03abd7dc4a481d50e2320e46060c546ddc146e2d4da3e8f381dc08b0a"
  }'; echo

pause "10. check score -> expect n=5, confirmations=4, overturns=1, score=0.309186"
curl -s "$BASE/validators/$SUBMITTER/score"; echo

pause "11. public verification endpoint for claim 2 (merkle proof + current_score)"
curl -s "$BASE/verify/$CLAIM2_HASH"; echo

pause "12. download mirror for offline verifier"
curl -s "$BASE/validators/$SUBMITTER/events" > mirror.json
echo "wrote mirror.json"

pause "13. offline verifier CLI -> expect PASS, score 0.309186"
npx tsx cli/verify.ts --pubkey "$SUBMITTER" --ledger-file ./mirror.json

echo
echo "Done. Total elapsed target: ~5 minutes of narrated screen time."
