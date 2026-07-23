<?php

namespace Ubxty\CoreAi\Standards\OpenAI;

/**
 * Pure formatting helpers for the OpenAI Chat Completions wire format.
 *
 * Extracted from azure-ai's local `formatMessages` / `formatImageBlock` /
 * `formatDocumentBlock` and stripped of every Azure-specific concern
 * (v1 vs traditional endpoint detection, api-key vs Bearer). The
 * OpenAIClient only knows the chat-completions body shape.
 *
 * Trait consumers also inherit prompt-cache marker injection (`applyCacheControl`)
 * which targets OpenAI's per-content-item `cache_control: { type: 'ephemeral' }`
 * marker (used by Azure OpenAI's automatic-prompt-caching opt-out path and
 * mirrored by other OpenAI-compatible providers that adopt the marker).
 */
trait HasOpenAIFormatting
{
    /**
     * Named anchors where the manager wants a `cache_control` marker injected.
     * Supported values: 'system', 'last_user'.
     *
     * Mirrors the Bedrock Converse trait's API so platform managers can be
     * written against a single anchor vocabulary.
     *
     * @var string[]
     */
    protected array $promptCachePoints = [];

    /**
     * Format messages into the OpenAI chat-completions format.
     *
     * Supports plain-text messages (`content` is a string) and multimodal
     * messages (`content` is an array of typed blocks: text, image, document).
     * Document blocks are flattened to text — the OpenAI Chat Completions
     * API does not natively accept document uploads.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array<int, array{role: string, content: string|array}>
     */
    protected function formatMessages(array $messages, string $systemPrompt = ''): array
    {
        $formatted = [];

        if ($systemPrompt !== '') {
            $formatted[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $formatted[] = ['role' => $role, 'content' => $content];

                continue;
            }

            // Multimodal: convert to OpenAI content array format.
            if (is_array($content)) {
                $parts = [];

                foreach ($content as $block) {
                    if (isset($block['text'])) {
                        $parts[] = ['type' => 'text', 'text' => $block['text']];
                    } elseif (isset($block['type']) && $block['type'] === 'text') {
                        $parts[] = $block;
                    } elseif (isset($block['image'])) {
                        $parts[] = $this->formatImageBlock($block['image']);
                    } elseif (isset($block['type']) && $block['type'] === 'image_url') {
                        $parts[] = $block;
                    } elseif (isset($block['document'])) {
                        $parts[] = $this->formatDocumentBlock($block['document']);
                    }
                }

                $formatted[] = ['role' => $role, 'content' => $parts];

                continue;
            }

            $formatted[] = ['role' => $role, 'content' => (string) $content];
        }

        return $formatted;
    }

    /**
     * Format an image block for the OpenAI vision API.
     *
     * @return array<string, mixed>
     */
    protected function formatImageBlock(array $imageData): array
    {
        if (isset($imageData['source']['bytes'])) {
            $bytes = $imageData['source']['bytes'];
            $format = $imageData['format'] ?? 'jpeg';
            $mimeType = "image/{$format}";
            $base64 = base64_encode($bytes);

            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mimeType};base64,{$base64}",
                ],
            ];
        }

        if (isset($imageData['url'])) {
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageData['url'],
                ],
            ];
        }

        return ['type' => 'text', 'text' => '[Image could not be processed]'];
    }

    /**
     * Format a document block. The OpenAI Chat Completions API does not
     * natively support document uploads, so we extract text content
     * (or base64-encode binary content) and inline it.
     *
     * @return array<string, mixed>
     */
    protected function formatDocumentBlock(array $documentData): array
    {
        $name = $documentData['name'] ?? 'document';

        if (isset($documentData['source']['bytes'])) {
            $bytes = $documentData['source']['bytes'];
            $format = $documentData['format'] ?? 'txt';

            if (in_array($format, ['txt', 'md', 'csv', 'html', 'htm'], true)) {
                return [
                    'type' => 'text',
                    'text' => "[Document: {$name}]\n\n".$bytes,
                ];
            }

            return [
                'type' => 'text',
                'text' => "[Document: {$name} ({$format}, ".strlen($bytes)." bytes) — binary content sent as base64]\n\n".base64_encode($bytes),
            ];
        }

        return ['type' => 'text', 'text' => "[Document: {$name} — content not available]"];
    }

    /**
     * Inject `cache_control: { type: 'ephemeral' }` markers into the chat
     * body at the configured named anchors.
     *
     * Currently supported anchors:
     *   - 'system'    — add cache_control to the system message's content.
     *   - 'last_user' — add cache_control to the last user message's last text part.
     *
     * @param  array<int, array{role: string, content: string|array<int, mixed>}>  $messages
     * @return array{0: array<int, array{role: string, content: string|array<int, mixed>}>}
     */
    protected function applyCacheControl(array $messages): array
    {
        if (empty($this->promptCachePoints)) {
            return [$messages];
        }

        if (in_array('system', $this->promptCachePoints, true) && ! empty($messages)
            && ($messages[0]['role'] ?? null) === 'system') {
            $messages[0] = $this->annotateMessageContent($messages[0]);
        }

        if (in_array('last_user', $this->promptCachePoints, true)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'user') {
                    $messages[$i] = $this->annotateMessageContent($messages[$i]);
                    break;
                }
            }
        }

        return [$messages];
    }

    /**
     * Add `cache_control: { type: 'ephemeral' }` to the last eligible content
     * part of the given message. Plain-string content is converted to a parts
     * array first. Only text and image_url parts can carry the marker.
     *
     * @param  array{role: string, content: string|array<int, mixed>}  $message
     * @return array{role: string, content: string|array<int, mixed>}
     */
    protected function annotateMessageContent(array $message): array
    {
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            $content = [['type' => 'text', 'text' => $content]];
        } elseif (! is_array($content)) {
            return $message;
        }

        if (empty($content)) {
            return $message;
        }

        for ($i = count($content) - 1; $i >= 0; $i--) {
            $type = $content[$i]['type'] ?? null;
            if ($type === 'text' || $type === 'image_url') {
                $content[$i]['cache_control'] = ['type' => 'ephemeral'];
                break;
            }
        }

        $message['content'] = $content;

        return $message;
    }
}