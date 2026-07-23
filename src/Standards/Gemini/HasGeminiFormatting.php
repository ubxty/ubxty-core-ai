<?php

namespace Ubxty\CoreAi\Standards\Gemini;

/**
 * Pure formatting helpers for the Google Gemini `generateContent` wire format.
 *
 * Gemini's wire format is materially different from OpenAI/Anthropic:
 *   - No `messages[]` of `{role, content}` — instead `contents[]` of `{role, parts[]}`.
 *   - `role` is one of `user` / `model` (not `assistant`).
 *   - System instructions are a separate top-level `systemInstruction.parts[]`.
 *   - Tools are `tools[0].functionDeclarations[]`.
 *   - Multimodal is `parts[]` of `{text, inlineData, fileData, functionCall, functionResponse}`.
 *
 * Trait consumers do NOT inherit cache-marker injection from this trait —
 * Gemini's caching is opt-in via a `cachedContent` reference (a cached
 * resource name returned from a prior request), which can't be applied at
 * request-build time without that prior name. `AbstractAiManager::converse`
 * still threads `cacheAnchors` through to `buildRequest()`; subclasses
 * that want Gemini caching should override `buildRequest()` to translate
 * the anchors into a `cachedContent` lookup.
 */
trait HasGeminiFormatting
{
    /**
     * Format messages into the Gemini `contents[]` shape.
     *
     * Translates `assistant` → `model` (Gemini's wire role) and unwraps
     * each message's content into a `parts[]` array. Plain-string content
     * becomes a single `{text}` part; multimodal arrays become one part
     * per block.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array<int, array{role: string, parts: array<int, mixed>}>
     */
    protected function formatMessages(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            // Gemini uses `model` for assistant turns.
            $role = $role === 'assistant' ? 'model' : 'user';
            $content = $message['content'] ?? '';

            $parts = [];

            if (is_string($content)) {
                $parts[] = ['text' => $content];
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    $type = (string) ($block['type'] ?? 'text');

                    if ($type === 'text') {
                        $parts[] = ['text' => (string) ($block['text'] ?? '')];
                    } elseif ($type === 'image' || $type === 'image_url') {
                        if (isset($block['image']['source']['bytes'])) {
                            $format = (string) ($block['image']['format'] ?? 'jpeg');
                            $parts[] = [
                                'inlineData' => [
                                    'mimeType' => "image/{$format}",
                                    'data' => base64_encode((string) $block['image']['source']['bytes']),
                                ],
                            ];
                        } elseif (isset($block['image_url']['url'])) {
                            $parts[] = [
                                'fileData' => [
                                    'fileUri' => (string) $block['image_url']['url'],
                                ],
                            ];
                        }
                    } elseif ($type === 'document') {
                        if (isset($block['document']['source']['bytes'])) {
                            $format = (string) ($block['document']['format'] ?? 'pdf');
                            $parts[] = [
                                'inlineData' => [
                                    'mimeType' => "application/{$format}",
                                    'data' => base64_encode((string) $block['document']['source']['bytes']),
                                ],
                            ];
                        }
                    } elseif (isset($block['text'])) {
                        $parts[] = ['text' => (string) $block['text']];
                    }
                }
            }

            $contents[] = ['role' => $role, 'parts' => $parts];
        }

        return $contents;
    }

    /**
     * Format a system prompt into Gemini's `systemInstruction.parts[]` shape.
     *
     * @return array{systemInstruction: array{parts: array<int, array{text: string}>}}
     */
    protected function formatSystemInstruction(string $systemPrompt): array
    {
        if ($systemPrompt === '') {
            return [];
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
        ];
    }
}