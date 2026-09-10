<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

final class PasskeyCounterAnomalyException extends PasskeyVerificationException
{
    public function __construct(public readonly int $credentialId)
    {
        parent::__construct('Ověření passkey selhalo.');
    }
}
