<?php

declare(strict_types=1);

namespace Provenance\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use Provenance\Crypto\Encoding;
use Provenance\Crypto\Messages;

final class MessagesTest extends TestCase
{
    public function testClaimTimestampMessageConcatenatesHashBytesAndTimestampString(): void
    {
        $claimHash = '0x' . str_repeat('ab', 32);
        $message = Messages::claimTimestampMessage($claimHash, 12345);
        $this->assertSame(Encoding::hexToBytes($claimHash) . '12345', $message);
    }

    public function testRotationMessageConcatenatesBothPubkeys(): void
    {
        $old = '0x' . str_repeat('01', 32);
        $new = '0x' . str_repeat('02', 32);
        $message = Messages::rotationMessage($old, $new);
        $this->assertSame(Encoding::hexToBytes($old) . Encoding::hexToBytes($new), $message);
    }

    public function testBatchMessageConcatenatesRootAndTimestampString(): void
    {
        $root = '0x' . str_repeat('03', 32);
        $message = Messages::batchMessage($root, 999);
        $this->assertSame(Encoding::hexToBytes($root) . '999', $message);
    }

    public function testLargeTimestampDoesNotUseScientificNotation(): void
    {
        // 1772000000000 is well within PHP's native int range on 64-bit,
        // so (string) cast must give exact digits, not float formatting.
        $claimHash = '0x' . str_repeat('ab', 32);
        $message = Messages::claimTimestampMessage($claimHash, 1772000000000);
        $this->assertStringEndsWith('1772000000000', $message);
    }
}
