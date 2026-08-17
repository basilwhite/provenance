<?php

require __DIR__ . '/../vendor/autoload.php';

use Provenance\Ledger\Hash;
use Provenance\Ledger\Merkle;

$response = json_decode(
    file_get_contents('C:/Users/basil/AppData/Local/Temp/claude/c--provenance/82aeb080-e6c3-46ab-8679-0f448cafcb7d/scratchpad/ts-audit-response.json'),
    true
);
$event = $response['event'];

$eventForHash = [
    'claim_hash' => $event['claim_hash'],
    'evidence_uri' => $event['evidence_uri'],
    'timestamp' => $event['timestamp'],
    'validator_pubkey' => $event['validator_pubkey'],
    'signature' => $event['signature'],
    'audit_ref' => $event['audit_ref'],
    'audit_verdict' => $event['audit_verdict'],
    'stake_locked' => $event['stake_locked'],
    'stake_slashed' => $event['stake_slashed'],
    'batch_root' => $event['batch_root'],
    'type' => $event['type'],
];

$leaf = Hash::computeLeafHash($eventForHash);
$blockRoot = Merkle::buildMerkleTree([$leaf])['root'];
$root = Hash::computeChainRoot($event['prev_root'], $blockRoot);

echo "PHP computed root:  $root\n";
echo "TS reported root:   {$event['root']}\n";
echo "ROOT MATCH (with audit_ref set + audit_verdict=true): " . ($root === $event['root'] ? 'true' : 'false') . "\n";

exit($root === $event['root'] ? 0 : 1);
