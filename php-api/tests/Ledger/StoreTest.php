<?php

declare(strict_types=1);

namespace Provenance\Tests\Ledger;

use Provenance\Ledger\Hash;
use Provenance\Ledger\Replay;
use Provenance\Ledger\Store;
use Provenance\Tests\Support\DbTestCase;

final class StoreTest extends DbTestCase
{
    private function submissionInput(array $overrides = []): array
    {
        return array_merge([
            'type' => 'submission',
            'claim_hash' => $this->hex('11'),
            'evidence_uri' => 'https://example.com/e',
            'timestamp' => 1000,
            'validator_pubkey' => $this->hex('22'),
            'signature' => '0x' . str_repeat('33', 64),
        ], $overrides);
    }

    public function testStartsWithGenesisRootAsLatestRoot(): void
    {
        $store = new Store($this->db);
        $this->assertSame(Hash::genesisRoot(), $store->getLatestRoot());
    }

    public function testAppendEventChainsPrevRootToPriorLatestRoot(): void
    {
        $store = new Store($this->db);
        $e1 = $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('01')]));
        $this->assertSame(Hash::genesisRoot(), $e1['prev_root']);

        $e2 = $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('02')]));
        $this->assertSame($e1['root'], $e2['prev_root']);
        $this->assertNotSame($e1['root'], $e2['root']);
    }

    public function testGetLatestRootReflectsMostRecentlyAppendedEvent(): void
    {
        $store = new Store($this->db);
        $e1 = $store->appendEvent($this->submissionInput());
        $this->assertSame($e1['root'], $store->getLatestRoot());
    }

    public function testRoundTripsAFullEventThroughInsertAndGetById(): void
    {
        $store = new Store($this->db);
        $input = $this->submissionInput(['stake_locked' => 42]);
        $stored = $store->appendEvent($input);
        $fetched = $store->getById($stored['id']);

        $this->assertNotNull($fetched);
        $this->assertSame($input['claim_hash'], $fetched['claim_hash']);
        $this->assertSame($input['evidence_uri'], $fetched['evidence_uri']);
        $this->assertSame($input['timestamp'], $fetched['timestamp']);
        $this->assertSame(42, $fetched['stake_locked']);
        $this->assertNull($fetched['audit_verdict']);
    }

    public function testRoundTripsAuditVerdictTrueFalseNullCorrectly(): void
    {
        $store = new Store($this->db);
        $t = $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('a1')]));
        $confirmed = $store->appendEvent($this->submissionInput([
            'claim_hash' => $this->hex('a2'), 'type' => 'audit', 'audit_verdict' => true, 'audit_ref' => $t['claim_hash'],
            'validator_pubkey' => $this->hex('c1'),
        ]));
        $overturned = $store->appendEvent($this->submissionInput([
            'claim_hash' => $this->hex('a3'), 'type' => 'audit', 'audit_verdict' => false, 'audit_ref' => $t['claim_hash'],
            'validator_pubkey' => $this->hex('c2'),
        ]));

        $this->assertTrue($store->getById($confirmed['id'])['audit_verdict']);
        $this->assertFalse($store->getById($overturned['id'])['audit_verdict']);
    }

    public function testGetEventsByPubKeyReturnsOnlyThatValidatorsEventsInOrder(): void
    {
        $store = new Store($this->db);
        $pkA = $this->hex('aa');
        $pkB = $this->hex('bb');
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('01'), 'validator_pubkey' => $pkA]));
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('02'), 'validator_pubkey' => $pkB]));
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('03'), 'validator_pubkey' => $pkA]));

        $eventsA = $store->getEventsByPubKey($pkA);
        $this->assertCount(2, $eventsA);
        foreach ($eventsA as $e) {
            $this->assertSame($pkA, $e['validator_pubkey']);
        }
    }

    public function testGetEventByClaimHashReturnsOriginalPlusAudits(): void
    {
        $store = new Store($this->db);
        $original = $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('05')]));
        $audit = $store->appendEvent($this->submissionInput([
            'claim_hash' => $original['claim_hash'], 'type' => 'audit', 'audit_ref' => $original['claim_hash'], 'audit_verdict' => true,
        ]));

        $results = $store->getEventByClaimHash($original['claim_hash']);
        $ids = array_map(static fn($e) => $e['id'], $results);
        sort($ids);
        $expected = [$original['id'], $audit['id']];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testRecomputesSameRootAsStoredWhenNothingTampered(): void
    {
        $store = new Store($this->db);
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('01')]));
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('02')]));
        $last = $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('03')]));

        $result = Replay::replayChain($store->getAllEvents());
        $this->assertTrue($result['valid']);
        $this->assertSame($last['root'], $result['recomputedLatestRoot']);
        $this->assertSame($store->getLatestRoot(), $result['recomputedLatestRoot']);
    }

    public function testDetectsTamperingWithAHistoricalEventsField(): void
    {
        $store = new Store($this->db);
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('01')]));
        $second = $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('02')]));
        $store->appendEvent($this->submissionInput(['claim_hash' => $this->hex('03')]));

        // Bypass the trigger (as an attacker with DB access would need to)
        // to simulate tampering and prove replay catches it.
        $this->db->exec('DROP TRIGGER IF EXISTS ledger_events_no_update');
        $stmt = $this->db->prepare('UPDATE ledger_events SET evidence_uri = ? WHERE id = ?');
        $stmt->execute(['tampered!', $second['id']]);

        $result = Replay::replayChain($store->getAllEvents());
        $this->assertFalse($result['valid']);
        $this->assertSame($second['id'], $result['mismatchAtEventId']);
    }

    public function testResolveIdentityLineageForANonRotatedKeyIsJustItself(): void
    {
        $store = new Store($this->db);
        $pubkey = $this->hex('cc');
        $this->assertSame([$pubkey], $store->resolveIdentityLineage($pubkey));
    }
}
