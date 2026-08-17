<?php

declare(strict_types=1);

namespace Provenance\Tests\Ledger;

use PHPUnit\Framework\TestCase;
use Provenance\Crypto\Encoding;
use Provenance\Ledger\Merkle;

final class MerkleTest extends TestCase
{
    private function leaf(string $s): string
    {
        return Encoding::keccak256Hex($s);
    }

    public function testValidProofForEveryLeafInOddSizedTree(): void
    {
        $leaves = array_map($this->leaf(...), ['a', 'b', 'c', 'd', 'e']);
        $tree = Merkle::buildMerkleTree($leaves);
        foreach ($leaves as $i => $l) {
            $proof = Merkle::getMerkleProof($tree['layers'], $i);
            $this->assertTrue(Merkle::verifyMerkleProof($l, $proof, $tree['root']));
        }
    }

    public function testValidProofForEveryLeafInEvenSizedTree(): void
    {
        $leaves = array_map($this->leaf(...), ['a', 'b', 'c', 'd']);
        $tree = Merkle::buildMerkleTree($leaves);
        foreach ($leaves as $i => $l) {
            $proof = Merkle::getMerkleProof($tree['layers'], $i);
            $this->assertTrue(Merkle::verifyMerkleProof($l, $proof, $tree['root']));
        }
    }

    public function testSingleLeafTreeRootEqualsLeaf(): void
    {
        $l = $this->leaf('only');
        $tree = Merkle::buildMerkleTree([$l]);
        $this->assertSame($l, $tree['root']);
        $this->assertTrue(Merkle::verifyMerkleProof($l, Merkle::getMerkleProof($tree['layers'], 0), $tree['root']));
    }

    public function testRejectsProofAgainstTamperedLeaf(): void
    {
        $leaves = array_map($this->leaf(...), ['a', 'b', 'c', 'd']);
        $tree = Merkle::buildMerkleTree($leaves);
        $proof = Merkle::getMerkleProof($tree['layers'], 1);
        $this->assertFalse(Merkle::verifyMerkleProof($this->leaf('tampered'), $proof, $tree['root']));
    }

    public function testRejectsProofAgainstTamperedRoot(): void
    {
        $leaves = array_map($this->leaf(...), ['a', 'b', 'c', 'd']);
        $tree = Merkle::buildMerkleTree($leaves);
        $proof = Merkle::getMerkleProof($tree['layers'], 1);
        $this->assertFalse(Merkle::verifyMerkleProof($leaves[1], $proof, $this->leaf('fake-root')));
    }

    public function testChangingAnyLeafChangesTheRoot(): void
    {
        $leaves = array_map($this->leaf(...), ['a', 'b', 'c', 'd', 'e']);
        $original = Merkle::buildMerkleTree($leaves)['root'];
        foreach ($leaves as $i => $l) {
            $tampered = $leaves;
            $tampered[$i] = $this->leaf($l . '-tampered');
            $this->assertNotSame($original, Merkle::buildMerkleTree($tampered)['root']);
        }
    }

    public function testHashPairIsOrderIndependent(): void
    {
        $x = $this->leaf('x');
        $y = $this->leaf('y');
        $this->assertSame(Merkle::hashPair($x, $y), Merkle::hashPair($y, $x));
    }

    public function testDeterministicGivenSameLeaves(): void
    {
        $leaves = array_map($this->leaf(...), ['a', 'b', 'c']);
        $this->assertSame(Merkle::buildMerkleTree($leaves)['root'], Merkle::buildMerkleTree($leaves)['root']);
    }
}
