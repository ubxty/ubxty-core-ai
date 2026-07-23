<?php

namespace Ubxty\CoreAi\Standards\Converse;

/**
 * Pure formatting helpers for the AWS Bedrock Converse wire format.
 *
 * Extracted from bedrock-ai's local HasRetryLogic and stripped of every
 * Credentials / AWS SDK / retry / cost-calc concern. The ConverseClient
 * (which uses this trait) owns the SDK vs HTTP Bearer branching; this
 * trait only knows how to shape messages, inject cachePoint blocks,
 * classify cachePoint strategies, and pick the effective input-token
 * count out of a Converse `usage` block.
 */
trait HasConverseFormatting
{
    /**
     * Named anchors where the manager wants a `cachePoint` block injected.
     * Supported values:
     *   - 'system'              after the system prompt blocks
     *   - 'last_user'           after the last user message blocks
     *   - 'last_assistant'      after the last assistant message blocks
     *   - 'every_n_assistant:N' after every Nth assistant message (N >= 1)
     *
     * Bedrock accepts up to 4 cachePoints per Converse call —
     * {@see applyCachePoints()} clamps the total emitted anchors to that
     * ceiling.
     *
     * @var string[]
     */
    protected array $promptCachePoints = [];

    /**
     * Bedrock cachePoint `type` value. 'default' keeps the model-default TTL;
     * '1h' pins a one-hour cache window (matches BEDROCK_PROMPT_CACHE_TTL).
     */
    protected string $promptCachePointType = 'default';

    /**
     * Glob patterns of model_ids that support prompt caching.
     * Empty = "all models" — preserved so cachePoint markers are emitted
     * for every model the user pointed at. Populated = the package only
     * emits cachePoint markers when the resolved model_id matches at least
     * one pattern. The match is performed with the cross-region inference
     * profile prefix (us.|eu.|apac.|ca.) stripped so
     * `us.anthropic.claude-3-5-sonnet-…` still matches `anthropic.claude*`.
     *
     * @var string[]
     */
    protected array $cacheSupportedModels = [];

    /**
     * Bedrock hard cap on cachePoints per Converse call.
     * Excess configured anchors are dropped (last-wins ordering).
     */
    protected const MAX_CACHE_POINTS = 4;

    /**
     * Format messages into the Converse API format.
     *
     * Supports plain-text messages (`content` is a string) and
     * multimodal messages (`content` is an array of typed blocks: text,
     * image, document). Image/document blocks carry `data` as a
     * base64-encoded string suitable for the HTTP/JSON wire format;
     * the SDK path (if used by the consuming client) is responsible
     * for any base64-decoding required by the AWS PHP SDK.
     *
     * The $system parameter is accepted for symmetry with the converse
     * pipeline but is not consumed here — system blocks are formatted
     * separately by the caller.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  ?array<int, array{text?: string}>  $system
     * @return array<int, array{role: string, content: array}>
     */
    protected function formatMessages(array $messages, ?array $system = null): array
    {
        return array_map(function (array $msg) {
            $content = $msg['content'];

            if (is_string($content)) {
                return [
                    'role' => $msg['role'],
                    'content' => [['text' => $content]],
                ];
            }

            $blocks = [];
            foreach ($content as $block) {
                $type = $block['type'] ?? 'text';

                if ($type === 'text') {
                    $blocks[] = ['text' => $block['text']];
                } elseif ($type === 'image') {
                    $blocks[] = [
                        'image' => [
                            'format' => $block['format'],
                            'source' => ['bytes' => $block['data']],
                        ],
                    ];
                } elseif ($type === 'document') {
                    $blocks[] = [
                        'document' => [
                            'format' => $block['format'],
                            'name'   => $block['name'] ?? 'document',
                            'source' => ['bytes' => $block['data']],
                        ],
                    ];
                }
            }

            return ['role' => $msg['role'], 'content' => $blocks];
        }, $messages);
    }

    /**
     * Inject `cachePoint` blocks into the Converse body at the configured
     * named anchors. Empty $this->promptCachePoints is a no-op.
     *
     * Anchors are emitted in conversation order (system first, then by
     * message index) so a multi-turn chat reuses progressively longer
     * cached prefixes — single biggest input-cost saving for chat
     * workloads.
     *
     * The Bedrock Converse API allows up to 4 cachePoints per call; the
     * total number of anchors actually written is clamped to
     * {@see MAX_CACHE_POINTS}. When the ceiling is hit, earlier anchors
     * win (system > last_user > last_assistant > every_n_assistant).
     *
     * When $modelId is non-empty and doesn't match the configured
     * `cacheSupportedModels` allowlist, this is also a no-op — the
     * model family rejects cachePoint markers with a 400/403, so we
     * skip them.
     *
     * @param  array<int, array{role: string, content: array<int, mixed>}>  $messages
     * @param  array<int, array{text?: string}>  $system
     * @param  string  $modelId  Resolved model_id (with cross-region prefix).
     * @return array{0: array<int, array{role: string, content: array<int, mixed>}>, 1: array<int, array{text?: string|cachePoint?: array}>}
     */
    protected function applyCachePoints(array $messages, array $system, string $modelId): array
    {
        if (empty($this->promptCachePoints)) {
            return [$messages, $system];
        }

        if (! $this->supportsCaching($modelId)) {
            return [$messages, $system];
        }

        $type = ['type' => $this->promptCachePointType];
        $remaining = self::MAX_CACHE_POINTS;

        if ($remaining > 0 && in_array('system', $this->promptCachePoints, true) && ! empty($system)) {
            $system[] = ['cachePoint' => $type];
            $remaining--;
        }

        if ($remaining > 0 && in_array('last_user', $this->promptCachePoints, true) && ! empty($messages)) {
            // Find the last message with role 'user' and append the checkpoint to its content.
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'user') {
                    if (! is_array($messages[$i]['content'])) {
                        // Plain string content: convert to a block array.
                        $messages[$i]['content'] = [['text' => (string) $messages[$i]['content']]];
                    }
                    $messages[$i]['content'][] = ['cachePoint' => $type];
                    $remaining--;
                    break;
                }
            }
        }

        if ($remaining > 0 && in_array('last_assistant', $this->promptCachePoints, true) && ! empty($messages)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'assistant') {
                    if (! is_array($messages[$i]['content'])) {
                        $messages[$i]['content'] = [['text' => (string) $messages[$i]['content']]];
                    }
                    $messages[$i]['content'][] = ['cachePoint' => $type];
                    $remaining--;
                    break;
                }
            }
        }

        if ($remaining > 0 && ! empty($messages)) {
            // Pre-compute every_n_assistant:N intervals (one per N), then
            // emit a cachePoint at each Nth assistant message — walking
            // from the end backwards so we always fill with the
            // most-recent eligible anchors first (best cache-hit reuse
            // for chat workloads).
            $intervals = [];
            foreach ($this->promptCachePoints as $strategy) {
                $n = $this->everyNAssistantInterval($strategy);
                if ($n !== null) {
                    $intervals[$n] = true;
                }
            }
            $intervals = array_keys($intervals);

            if (! empty($intervals)) {
                $assistantIndexes = [];
                foreach ($messages as $i => $msg) {
                    if (($msg['role'] ?? null) === 'assistant') {
                        $assistantIndexes[] = $i;
                    }
                }

                // For each interval N, pick the (assistantCount mod N == 0)
                // assistant indexes walking from the newest backwards.
                // Emit at most one anchor per N until the cap is hit.
                foreach ($intervals as $n) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $count = 0;
                    for ($k = count($assistantIndexes) - 1; $k >= 0; $k--) {
                        $count++;
                        if ($count % $n !== 0) {
                            continue;
                        }
                        $idx = $assistantIndexes[$k];
                        if (! is_array($messages[$idx]['content'])) {
                            $messages[$idx]['content'] = [['text' => (string) $messages[$idx]['content']]];
                        }
                        // cachePoint must remain the LAST element of
                        // messages[i].content per the Bedrock Converse spec.
                        $messages[$idx]['content'][] = ['cachePoint' => $type];
                        $remaining--;
                        if ($remaining <= 0) {
                            break;
                        }
                    }
                }
            }
        }

        return [$messages, $system];
    }

    /**
     * Decide whether cachePoint markers should be emitted for the given
     * resolved model_id. Returns true when no allowlist is configured
     * (preserves legacy "apply caching everywhere" behaviour) and false
     * when the model_id doesn't match any configured pattern.
     *
     * Cross-region inference profile prefixes (`us.`, `eu.`, `apac.`,
     * `ca.`) are stripped before matching so the same pattern covers
     * prefixed and unprefixed variants.
     */
    protected function supportsCaching(string $modelId): bool
    {
        if (empty($this->cacheSupportedModels)) {
            return true;
        }

        $normalized = preg_replace('/^(?:us|eu|apac|ca)\./', '', $modelId) ?? $modelId;

        foreach ($this->cacheSupportedModels as $pattern) {
            if (fnmatch($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the effective input-token count from a Converse `usage` block.
     *
     * Amazon Nova (Pro/Lite/Micro) reports `inputTokens=0` for cached
     * requests and surfaces the real count via `cacheReadInputTokens +
     * cacheWriteInputTokens`. Anthropic Claude reports the total via
     * `inputTokens` and the cache fields as subsets, so summing them all
     * would double-count. We pick whichever reading is non-zero so both
     * model families surface the correct effective input to callers.
     */
    protected function effectiveInputTokens(int $input, int $cacheRead, int $cacheWrite): int
    {
        if ($input > 0) {
            return $input;
        }

        return $cacheRead + $cacheWrite;
    }

    /**
     * Validate a configured cache-point strategy string.
     */
    protected function isValidCachePointStrategy(string $strategy): bool
    {
        if (in_array($strategy, ['system', 'last_user', 'last_assistant'], true)) {
            return true;
        }

        // 'every_n_assistant:N' where N is a positive integer.
        if (preg_match('/^every_n_assistant:([1-9]\d*)$/', $strategy, $m) === 1) {
            return (int) $m[1] >= 1;
        }

        return false;
    }

    /**
     * Parse the trailing integer from an 'every_n_assistant:N' strategy.
     * Returns null for non-matching strategy names.
     */
    protected function everyNAssistantInterval(string $strategy): ?int
    {
        if (preg_match('/^every_n_assistant:([1-9]\d*)$/', $strategy, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
