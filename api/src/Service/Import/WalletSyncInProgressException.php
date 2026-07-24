<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Wallet po vygenerování prvního API tokenu spouští interní synchronizaci dat
 * a do jejího dokončení vrací HTTP 409 — retryable stav, ne chyba tokenu.
 */
final class WalletSyncInProgressException extends \RuntimeException
{
}
