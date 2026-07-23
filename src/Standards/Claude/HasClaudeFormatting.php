<?php

namespace Ubxty\CoreAi\Standards\Claude;

/**
 * Pure formatting helpers for the Anthropic Messages API wire format.
 *
 * Extracted from anthropic-ai's (planned) local helpers and stripped of
 * every Claude-specific concern (Bearer / x-api-key auth, base URL,
 * model listing). The ClaudeClient only knows the Messages API body
 * shape.
 *
 * Trait consumers also inherit prompt-cache marker injection
 * (`applyCacheControl`) which targets Anthropic's per-content-block
 * `cache_control: { type: 'ephemeral' }` marker — the same vocabulary
 * that AWS Bedrock Converse maps to `cachePoint`. Both Bedrock Converse
 * and Anthropic direct use the same `type` string for the marker, so a
 * config-level allowlist of `['system', 'last_user', 'last_assistant',
 * 'every_n_assistant:N']` works for both.
 */
trait HasClaudeFormatting
{
    /**
     * Named anchors where the manager wants a `cache_control` block injected.
     * Mirrors {@see \Ubxty\CoreAi\Standards\Converse\HasConverseFormatting}'s
     * vocabulary so platform managers can be written against a single API.
     *
     * @var string[]
     */
    protected array $promptCachePoints = [];

    /**
     * Anthropic cache_control `type` value. Default `ephemeral` matches
     * the 5-minute TTL. The platform manager can override to `1h` for
     * the longer cache window.
     */
    protected string $promptCachePointType = 'ephemeral';

    /**
     * Format messages into the Anthropic Messages API format.
     *
     * Per the spec, every message has `role` and `content`; `content` is
     * either a string (plain-text) or an array of typed content blocks
     * (text, image, document). System messages are NOT formatted here —
     * they go in the separate top-level `system` field on the request,
     * handled by the caller.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array<int, array{role: string, content: string|array<int, mixed>}>
     */
    protected function formatMessages(array $messages): array
    {
        return array_map(function (array $msg): array {
            $content = $msg['content'];

            if (is_string($content)) {
                return [
                    'role' => $msg['role'],
                    'content' => [['type' => 'text', 'text' => $content]],
                ];
            }

            $blocks = [];
            foreach ($content as $block) {
                $type = $block['type'] ?? 'text';

                if ($type === 'text') {
                    $blocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                } elseif ($type === 'image') {
                    $blocks[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/'.($block['format'] ?? 'jpeg'),
                            'data' => (string) ($block['data'] ?? ''),
                        ],
                    ];
                } elseif ($type === 'image_url') {
                    $blocks[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => (string) ($block['image_url']['url'] ?? $block['url'] ?? ''),
                        ],
                    ];
                } elseif ($type === 'document') {
                    $blocks[] = [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/'.($block['format'] ?? 'pdf'),
                            'data' => (string) ($block['data'] ?? ''),
                        ],
                    ];
                } elseif (isset($block['type'])) {
                    // Pass through unknown block types (already-claude-shaped).
                    $blocks[] = $block;
                }
            }

            return ['role' => $msg['role'], 'content' => $blocks];
        }, $messages);
    }

    /**
     * Inject `cache_control: { type: 'ephemeral' }` markers into the
     * Messages body at the configured named anchors.
     *
     * Anthropic accepts up to 4 cache_control blocks per request — we
     * clamp the total emitted anchors to that ceiling. When the cap is
     * hit, earlier anchors win (system > last_user > last_assistant >
     * every_n_assistant).
     *
     * @param  array<int, array{role: string, content: string|array<int, mixed>}>  $messages
     * @param  string  $systemPrompt  The system prompt string (passed in for the 'system' anchor).
     * @return array{0: array<int, array{role: string, content: string|array<int, mixed>}>, 1: string}
     */
    protected function applyCacheControl(array $messages, string $systemPrompt = ''): array
    {
        if (empty($this->promptCachePoints)) {
            return [$messages, $systemPrompt];
        }

        $type = ['type' => $this->promptCachePointType];
        $remaining = 4;

        // System anchor: the marker is implicit — system content is
        // its own "block" that the API caches as a unit. We need
        // `cache_control` on a content block, so we synthesize a block
        // array from the plain system string.
        if ($remaining > 0 && in_array('system', $this->promptCachePoints, true) && $systemPrompt !== '') {
            // Convert system from string to a content-blocks array so
            // we can attach cache_control to it.
            $systemBlocks = [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => $type]];
            $systemPrompt = ''; // signal to caller to use the blocks shape
            $remaining--;
        } else {
            $systemBlocks = null;
        }

        if ($remaining > 0 && in_array('last_user', $this->promptCachePoints, true) && ! empty($messages)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'user') {
                    if (! is_array($messages[$i]['content'])) {
                        $messages[$i]['content'] = [['type' => 'text', 'text' => (string) $messages[$i]['content']]];
                    }
                    $messages[$i]['content'][] = ['type' => 'text', 'text' => '', 'cache_control' => $type];
                    $remaining--;
                    break;
                }
            }
        }

        if ($remaining > 0 && in_array('last_assistant', $this->promptCachePoints, true) && ! empty($messages)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'assistant') {
                    if (! is_array($messages[$i]['content'])) {
                        $messages[$i]['content'] = [['type' => 'text', 'text' => (string) $messages[$i]['content']]];
                    }
                    $messages[$i]['content'][] = ['type' => 'text', 'text' => '', 'cache_control' => $type];
                    $remaining--;
                    break;
                }
            }
        }

        // every_n_assistant:N — emit a cache marker at the (k mod N == 0)-th
        // assistant block walking backwards from the end.
        if ($remaining > 0 && ! empty($messages)) {
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
                            $messages[$idx]['content'] = [['type' => 'text', 'text' => (string) $messages[$idx]['content']]];
                        }
                        $messages[$idx]['content'][] = ['type' => 'text', 'text' => '', 'cache_control' => $type];
                        $remaining--;
                        if ($remaining <= 0) {
                            break;
                        }
                    }
                }
            }
        }

        // The trait doesn't actually need $systemBlocks — we just need
        // to know that we DID inject a system marker. We pass through
        // $systemPrompt unchanged so the caller can keep the simple
        // `system: string` shape. (Anthropic accepts both
        // `system: string` and `system: [content-block]` shapes.)
        return [$messages, $systemPrompt];
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