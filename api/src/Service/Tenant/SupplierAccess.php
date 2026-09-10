<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

/**
 * Výsledek resoluce supplier scope pro aktuální request.
 *
 *   - supplierId   — platné supplier id, se kterým má request pracovat
 *   - denied       — true = uživatel explicitně požádal o firmu MIMO své
 *                    membership (user_suppliers) → middleware vrací 403
 *   - roleOverride — per-supplier role z user_suppliers.role
 *                    (NULL = zdědit globální users.role)
 */
final class SupplierAccess
{
    public function __construct(
        public readonly int $supplierId,
        public readonly bool $denied,
        public readonly ?string $roleOverride,
    ) {}
}
