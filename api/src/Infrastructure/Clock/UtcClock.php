<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Clock;

use Psr\Clock\ClockInterface;

final class UtcClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
