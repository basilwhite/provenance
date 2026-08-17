<?php

require __DIR__ . '/../vendor/autoload.php';

use Provenance\Ledger\Hash;
use Provenance\Ledger\Merkle;

$response = json_decode(file_get_contents('C:/Users/basil/AppData/Local/Temp/claude/c--provenance/82aeb080-e6c3-46ab-8679-0f448cafcb7d/scratchpad/ts-submit-response.json'), true);
$event = $response['event'];

echo "TS-reported prev_root: {$event['prev_root']}\n";
echo "PHP genesisRoot():     " . Hash::genesisRoot() . "\n";
echo "MATCH: " . ($event['prev_root'] === Hash::genesisRoot() ? 'true' : 'false') . "\n\n";

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

echo "PHP computed leaf:  $leaf\n";
echo "PHP computed root:  $root\n";
echo "TS reported root:   {$event['root']}\n";
echo "ROOT MATCH: " . ($root === $event['root'] ? 'true' : 'false') . "\n";

exit($root === $event['root'] && $event['prev_root'] === Hash::genesisRoot() ? 0 : 1);
