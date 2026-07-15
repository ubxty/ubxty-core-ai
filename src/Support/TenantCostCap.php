<?php

namespace Ubxty\CoreAi\Support;

/**
 * Daily and monthly spend caps for a single tenant (USD).
 *
 * `0.0` on either axis means "no cap" — resolvers should return 0.0 for
 * axes they don't track, not throw. The core package uses these values
 * only as a seam; enforcement lives in the host application (T3-PR6).
 */
final readonly class TenantCostCap
{
    public function __construct(
        public float $daily = 0.0,
        public float $monthly = 0.0,
    ) {}
}
