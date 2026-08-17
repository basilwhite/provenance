<?php

$vectorsPath = 'C:/Users/basil/AppData/Local/Temp/claude/c--provenance/82aeb080-e6c3-46ab-8679-0f448cafcb7d/scratchpad/ed25519-vectors.json';
$vectors = json_decode(file_get_contents($vectorsPath), true);

function stripHexPrefix(string $hex): string
{
    return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
}

$seed = hex2bin(stripHexPrefix($vectors['seed_hex']));
$tsPublicKey = hex2bin(stripHexPrefix($vectors['public_key_hex']));
$message = hex2bin(stripHexPrefix($vectors['message_hex']));
$tsSignature = hex2bin(stripHexPrefix($vectors['signature_hex']));

echo "seed length: " . strlen($seed) . " bytes\n";
echo "ts public key length: " . strlen($tsPublicKey) . " bytes\n";
echo "message length: " . strlen($message) . " bytes\n";
echo "ts signature length: " . strlen($tsSignature) . " bytes\n\n";

// Derive keypair from the same 32-byte seed via libsodium.
$keypair = sodium_crypto_sign_seed_keypair($seed);
$phpPublicKey = sodium_crypto_sign_publickey($keypair);
$phpSecretKey = sodium_crypto_sign_secretkey($keypair);

echo "1. Public key derivation from seed:\n";
echo "   TS  public key: " . bin2hex($tsPublicKey) . "\n";
echo "   PHP public key: " . bin2hex($phpPublicKey) . "\n";
$pubKeyMatch = $phpPublicKey === $tsPublicKey;
echo "   MATCH: " . ($pubKeyMatch ? 'true' : 'false') . "\n\n";

// Verify the TS-produced signature using sodium.
echo "2. Verifying TS-produced signature via sodium_crypto_sign_verify_detached:\n";
$verifyResult = sodium_crypto_sign_verify_detached($tsSignature, $message, $tsPublicKey);
echo "   Result: " . ($verifyResult ? 'VALID' : 'INVALID') . "\n\n";

// Reverse direction: PHP signs the same message, output for TS to verify.
echo "3. PHP signs the same message (for TS to verify back):\n";
$phpSignature = sodium_crypto_sign_detached($message, $phpSecretKey);
echo "   PHP signature: 0x" . bin2hex($phpSignature) . "\n";
$phpSelfVerify = sodium_crypto_sign_verify_detached($phpSignature, $message, $phpPublicKey);
echo "   PHP self-verify: " . ($phpSelfVerify ? 'true' : 'false') . "\n\n";

// Also check garbage-signature rejection (malleability / fuzz safety), matching keys.test.ts.
$garbage = random_bytes(64);
$garbageResult = sodium_crypto_sign_verify_detached($garbage, $message, $tsPublicKey);
echo "4. Random 64-byte garbage signature rejected: " . ($garbageResult ? 'FALSE (BAD - accepted garbage!)' : 'true (correctly rejected)') . "\n\n";

$overall = $pubKeyMatch && $verifyResult && $phpSelfVerify && !$garbageResult;
echo "OVERALL: " . ($overall ? 'PASS - Ed25519 is cross-compatible' : 'FAIL - DO NOT PROCEED') . "\n";

file_put_contents(
    'C:/Users/basil/AppData/Local/Temp/claude/c--provenance/82aeb080-e6c3-46ab-8679-0f448cafcb7d/scratchpad/php-signature.json',
    json_encode(['php_signature_hex' => '0x' . bin2hex($phpSignature)])
);

exit($overall ? 0 : 1);
