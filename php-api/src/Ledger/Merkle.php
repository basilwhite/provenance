<?php

declare(strict_types=1);

namespace Provenance\Ledger;

use Provenance\Crypto\Encoding;

/** Mirrors src/ledger/merkle.ts. Leaves/proofs/roots are always 0x-prefixed hex strings. */
final class Merkle
{
    private static ?string $emptyTreeRoot = null;

    public static function hashPair(string $a, string $b): string
    {
        [$x, $y] = strtolower($a) <= strtolower($b) ? [$a, $b] : [$b, $a];
        return Encoding::keccak256Hex(Encoding::hexToBytes($x) . Encoding::hexToBytes($y));
    }

    private static function emptyTreeRoot(): string
    {
        if (self::$emptyTreeRoot === null) {
            self::$emptyTreeRoot = Encoding::keccak256Hex('');
        }
        return self::$emptyTreeRoot;
    }

    /** @param string[] $leaves @return array{root: string, layers: string[][]} */
    public static function buildMerkleTree(array $leaves): array
    {
        if (count($leaves) === 0) {
            $empty = self::emptyTreeRoot();
            return ['root' => $empty, 'layers' => [[$empty]]];
        }

        $layers = [array_values($leaves)];
        $current = array_values($leaves);

        while (count($current) > 1) {
            $next = [];
            for ($i = 0; $i < count($current); $i += 2) {
                $left = $current[$i];
                $right = $i + 1 < count($current) ? $current[$i + 1] : $left;
                $next[] = self::hashPair($left, $right);
            }
            $layers[] = $next;
            $current = $next;
        }

        return ['root' => $current[0], 'layers' => $layers];
    }

    /** @param string[][] $layers @return string[] */
    public static function getMerkleProof(array $layers, int $leafIndex): array
    {
        $leafCount = count($layers[0] ?? []);
        if ($leafIndex < 0 || $leafIndex >= $leafCount) {
            throw new \InvalidArgumentException("leafIndex {$leafIndex} out of range");
        }

        $proof = [];
        $index = $leafIndex;

        for ($level = 0; $level < count($layers) - 1; $level++) {
            $layer = $layers[$level];
            $isRightNode = $index % 2 === 1;
            $siblingIndex = $isRightNode ? $index - 1 : $index + 1;
            $sibling = $siblingIndex < count($layer) ? $layer[$siblingIndex] : $layer[$index];
            $proof[] = $sibling;
            $index = intdiv($index, 2);
        }

        return $proof;
    }

    /** @param string[] $proof */
    public static function verifyMerkleProof(string $leaf, array $proof, string $root): bool
    {
        $computed = $leaf;
        foreach ($proof as $sibling) {
            $computed = self::hashPair($computed, $sibling);
        }
        return strtolower($computed) === strtolower($root);
    }
}
