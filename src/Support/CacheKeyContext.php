<?php

namespace Ubxty\CoreAi\Support;

/**
 * Immutable cache-key context used to namespace response/embedding cache
 * entries by tenant, conversation, and prompt version.
 *
 * `resolve()` best-effort reads the current tenant id from the global
 * `tenant()` helper shipped by ubxty/multi-tenant-laravel-permissions. The
 * dependency is optional: when the helper is absent or the tenant context is
 * not booted, every field falls back to null so the caller degrades to the
 * shared (t0:c0:v0) namespace.
 */
final readonly class CacheKeyContext
{
    public function __construct(
        public ?int $tenantId = null,
        public ?string $conversationId = null,
        public ?string $promptVersion = null,
    ) {}

    /**
     * Build a context from the ambient tenant scope, degrading to null when the
     * multi-tenant helper is unavailable or throws (e.g. no booted container).
     */
    public static function resolve(
        ?string $conversationId = null,
        ?string $promptVersion = null,
    ): self {
        $tenantId = null;

        if (function_exists('tenant')) {
            try {
                $id = tenant('id');

                if (is_int($id)) {
                    $tenantId = $id;
                } elseif (is_numeric($id)) {
                    $tenantId = (int) $id;
                }
            } catch (\Throwable) {
                $tenantId = null;
            }
        }

        return new self(
            tenantId: $tenantId,
            conversationId: $conversationId,
            promptVersion: $promptVersion,
        );
    }
}
