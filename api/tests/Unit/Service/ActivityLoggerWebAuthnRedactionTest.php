<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\TestCase;

final class ActivityLoggerWebAuthnRedactionTest extends TestCase
{
    public function testWebAuthnPayloadMaterialIsRedactedRecursively(): void
    {
        $logger = new ActivityLogger($this->createStub(Connection::class));

        $redacted = $logger->redact([
            'flow_token' => 'secret-flow',
            'credential' => [
                'rawId' => 'secret-id',
                'response' => ['signature' => 'secret-signature'],
            ],
            'credential_id' => 42,
        ]);

        self::assertSame('[REDACTED]', $redacted['flow_token']);
        self::assertSame('[REDACTED]', $redacted['credential']);
        self::assertSame(42, $redacted['credential_id']);
    }
}
