<?php

namespace Ubxty\CoreAi\Contracts;

use Ubxty\CoreAi\Support\TenantCostCap;

/**
 * Resolves a tenant's spend cap (daily + monthly, USD).
 *
 * Implementations are host-application specific — the core package never
 * enforces the cap itself (T3-PR6 owns that in the host service); this
 * contract exists so the platform-side `checkCostLimits()` can be aware
 * of tenant limits when configured, without taking a hard dependency on
 * a specific resolver.
 *
 * Return `TenantCostCap` with `0.0` for any axis the host does not
 * enforce — the core side treats 0.0 as "no cap on this axis".
 */
interface TenantCostCapResolver
{
    public function resolve(int $tenantId): TenantCostCap;
}
