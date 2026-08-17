<?php

// PHP escapes forward slashes and non-ASCII by default; JS's JSON.stringify does neither.
// JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE should make them match.

$fields = [
    '0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72',
    'https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json',
    1772000000000,
    '0x4c8ee26ee906dcbd8dc8f16967ce5fd8817c987fd0516db5ac1d9076203fe9fe',
    '0x972928baa655bf6871cdbff245bbea0723ed46a80aefa95fc2e60853261af6ab185e10cc780024502679dcf7b53afd498f61ae3a154afd413a9a148a1d57d30c',
    null,
    null,
    10,
    0,
    null,
];

$default = json_encode($fields);
$matched = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "default json_encode:\n$default\n\n";
echo "with UNESCAPED_SLASHES|UNESCAPED_UNICODE:\n$matched\n";
