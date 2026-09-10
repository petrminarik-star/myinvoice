<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

final readonly class MfaStepUpProof
{
    public function __construct(
        public int $userId,
        public string $operation,
        public string $authMethod,
        public ?int $authCredentialId,
    ) {}
}
