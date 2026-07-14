<?php

namespace Ubxty\CoreAi\Conversation;

use Ubxty\CoreAi\Contracts\AiManagerContract;
use Ubxty\CoreAi\Exceptions\AiException;
use Ubxty\CoreAi\Support\TokenEstimator;

class ConversationBuilder
{
    protected string $modelId;

    protected string $systemPrompt = '';

    /** @var array<int, array{role: string, content: string|array}> */
    protected array $messages = [];

    protected int $maxTokens = 4096;

    protected float $temperature = 0.7;

    protected ?array $pricing = null;

    protected ?string $connection = null;

    protected ?array $schema = null;

    protected AiManagerContract $manager;

    public function __construct(AiManagerContract $manager, string $modelId)
    {
        $this->manager = $manager;
        $this->modelId = $modelId;
    }

    /**
     * Set the system prompt.
     */
    public function system(string $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    /**
     * Add a user message to the conversation.
     */
    public function user(string $message): static
    {
        $this->messages[] = ['role' => 'user', 'content' => $message];

        return $this;
    }

    /**
     * Add a user message with an image attachment.
     *
     * @param  string  $prompt  The question or instruction about the image.
     * @param  string  $source  Absolute file path or already base64-encoded image data.
     * @param  string  $format  Image format: jpeg, png, gif, webp. 'auto' detects from the file extension.
     */
    public function userWithImage(string $prompt, string $source, string $format = 'auto'): static
    {
        $base64 = $this->readAsBase64($source, 'Image');
        $format = $this->resolveImageFormat($source, $format);

        $this->messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'format' => $format, 'data' => $base64],
                ['type' => 'text', 'text' => $prompt],
            ],
        ];

        return $this;
    }

    /**
     * Add a user message with multiple document attachments in a single message.
     *
     * Each entry in $documents may be either a string (file path) or an
     * associative array with keys: path (string, required), format (string,
     * optional, 'auto' detects from extension), name (string, optional).
     *
     * @param  string  $prompt  The instruction that applies to all documents.
     * @param  array<int, string|array{path: string, format?: string, name?: string}>  $documents
     */
    public function userWithDocuments(string $prompt, array $documents): static
    {
        if (count($documents) === 0) {
            throw new AiException('At least one document is required.');
        }

        $content = [];
        foreach ($documents as $i => $doc) {
            if (is_string($doc)) {
                $source = $doc;
                $format = 'auto';
                $name = '';
            } else {
                $source = (string) ($doc['path'] ?? $doc[0] ?? '');
                $format = (string) ($doc['format'] ?? 'auto');
                $name = (string) ($doc['name'] ?? '');

                if ($source === '') {
                    throw new AiException("Document entry at index {$i} is missing 'path'.");
                }
            }

            $base64 = $this->readAsBase64($source, 'Document');
            $format = $this->resolveDocumentFormat($source, $format);
            if ($name === '' && is_file($source)) {
                $name = pathinfo($source, PATHINFO_FILENAME);
            }

            $content[] = ['type' => 'document', 'format' => $format, 'name' => $name ?: 'document', 'data' => $base64];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $this->messages[] = ['role' => 'user', 'content' => $content];

        return $this;
    }

    /**
     * Add a user message with mixed image/document attachments in a single message.
     *
     * Each entry in $attachments is an associative array:
     *   - type (string, required) — 'image' or 'document'
     *   - path (string, required) — absolute file path or pre-encoded base64
     *   - format (string, optional) — 'auto' to detect from extension
     *   - name (string, optional) — display name (documents only)
     *
     * @param  string  $prompt  The instruction that applies to all attachments.
     * @param  array<int, array{type: string, path: string, format?: string, name?: string}>  $attachments
     */
    public function userWithAttachments(string $prompt, array $attachments): static
    {
        if (count($attachments) === 0) {
            throw new AiException('At least one attachment is required.');
        }

        $content = [];
        foreach ($attachments as $i => $att) {
            $type = (string) ($att['type'] ?? '');
            $source = (string) ($att['path'] ?? '');
            $format = (string) ($att['format'] ?? 'auto');
            $name = (string) ($att['name'] ?? '');

            if ($source === '') {
                throw new AiException("Attachment entry at index {$i} is missing 'path'.");
            }

            $base64 = $this->readAsBase64($source, ucfirst($type));

            switch ($type) {
                case 'image':
                    $format = $this->resolveImageFormat($source, $format);
                    $content[] = ['type' => 'image', 'format' => $format, 'data' => $base64];
                    break;

                case 'document':
                    $format = $this->resolveDocumentFormat($source, $format);
                    if ($name === '' && is_file($source)) {
                        $name = pathinfo($source, PATHINFO_FILENAME);
                    }
                    $content[] = ['type' => 'document', 'format' => $format, 'name' => $name ?: 'document', 'data' => $base64];
                    break;

                default:
                    throw new AiException("Unknown attachment type '{$type}' (use 'image' or 'document') at index {$i}.");
            }
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $this->messages[] = ['role' => 'user', 'content' => $content];

        return $this;
    }

    /**
     * Add a user message with a single image. Shorthand for userWithImage().
     */
    public function image(string $source, string $prompt = '', string $format = 'auto'): static
    {
        return $this->userWithImage($prompt, $source, $format);
    }

    /**
     * Add a user message with a document attachment.
     *
     * @param  string  $prompt  The question or instruction about the document.
     * @param  string  $source  Absolute file path or already base64-encoded document data.
     * @param  string  $format  Document format: pdf, csv, doc, docx, xls, xlsx, html, txt, md. 'auto' detects.
     * @param  string  $name  Display name for the document (defaults to the filename).
     */
    public function userWithDocument(string $prompt, string $source, string $format = 'auto', string $name = ''): static
    {
        $base64 = $this->readAsBase64($source, 'Document');
        $format = $this->resolveDocumentFormat($source, $format);
        if ($name === '' && is_file($source)) {
            $name = pathinfo($source, PATHINFO_FILENAME);
        }

        $this->messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'document', 'format' => $format, 'name' => $name ?: 'document', 'data' => $base64],
                ['type' => 'text', 'text' => $prompt],
            ],
        ];

        return $this;
    }

    /**
     * Add an assistant message to the conversation (for multi-turn context).
     */
    public function assistant(string $message): static
    {
        $this->messages[] = ['role' => 'assistant', 'content' => $message];

        return $this;
    }

    /**
     * Set max output tokens.
     */
    public function maxTokens(int $tokens): static
    {
        $this->maxTokens = $tokens;

        return $this;
    }

    /**
     * Set temperature.
     */
    public function temperature(float $temp): static
    {
        $this->temperature = $temp;

        return $this;
    }

    /**
     * Set pricing for cost calculation.
     *
     * @param  array{input_price_per_1k: float, output_price_per_1k: float}  $pricing
     */
    public function withPricing(array $pricing): static
    {
        $this->pricing = $pricing;

        return $this;
    }

    /**
     * Use a specific connection.
     */
    public function connection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Override the model ID mid-build.
     */
    public function model(string $modelId): static
    {
        $this->modelId = $modelId;

        return $this;
    }

    /**
     * Request that the model respond with JSON matching the given schema.
     *
     * The schema is appended to the system prompt as a JSON Schema
     * instruction. It is a model-side instruction, not a hard wire-format
     * constraint, so model capability differs (newer Claude / GPT-4o+ /
     * Nova follow it reliably; older models treat it as guidance).
     *
     * @param  array<string, mixed>  $jsonSchema
     */
    public function schema(array $jsonSchema): static
    {
        $this->schema = $jsonSchema;

        return $this;
    }

    /**
     * Estimate token usage and cost before sending.
     *
     * @return array{input_tokens: int, available_output: int, fits: bool, context_window: int, estimated_cost: float}
     */
    public function estimate(): array
    {
        $allContent = implode(' ', array_map(function (array $m) {
            $content = $m['content'];
            if (is_string($content)) {
                return $content;
            }

            return implode(' ', array_filter(array_map(fn ($b) => $b['text'] ?? null, $content)));
        }, $this->messages));

        $estimation = TokenEstimator::estimateInvocation(
            $this->systemPrompt,
            $allContent,
            $this->modelId,
            $this->maxTokens
        );

        $estimation['estimated_cost'] = TokenEstimator::estimateCost(
            $this->systemPrompt,
            $allContent,
            $this->maxTokens,
            $this->pricing
        );

        return $estimation;
    }

    /**
     * Send the conversation (blocking).
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string, cost: float}
     */
    public function send(): array
    {
        $result = $this->manager->converse(
            $this->modelId,
            $this->messages,
            $this->effectiveSystemPrompt(),
            $this->maxTokens,
            $this->temperature,
            $this->connection,
            $this->pricing,
        );

        $this->messages[] = ['role' => 'assistant', 'content' => $result['response']];

        return $result;
    }

    /**
     * Send the conversation with streaming output.
     *
     * @param  callable(string $chunk): void  $onChunk
     */
    public function sendStream(callable $onChunk): array
    {
        $result = $this->manager->converseStream(
            $this->modelId,
            $this->messages,
            $onChunk,
            $this->effectiveSystemPrompt(),
            $this->maxTokens,
            $this->temperature,
            $this->connection,
            $this->pricing,
        );

        $this->messages[] = ['role' => 'assistant', 'content' => $result['response']];

        return $result;
    }

    /**
     * Alias for {@see sendStream()} returning the same assembled result array.
     *
     * Provided so callers that prefer the noun ("stream the conversation")
     * rather than the verb ("send with streaming") have a one-word entry.
     * For HTTP-level streaming (returning chunked bytes to a browser), wrap
     * this in Laravel's `response()->stream()` or Symfony StreamedResponse.
     *
     * @param  callable(string $chunk): void  $onChunk
     */
    public function stream(callable $onChunk): array
    {
        return $this->sendStream($onChunk);
    }

    /**
     * Get the current message history.
     *
     * @return array<int, array{role: string, content: string|array}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get the system prompt.
     */
    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /**
     * Get the model ID.
     */
    public function getModelId(): string
    {
        return $this->modelId;
    }

    /**
     * Get the JSON-schema request (null if not set).
     *
     * @return array<string, mixed>|null
     */
    public function getSchema(): ?array
    {
        return $this->schema;
    }

    /**
     * Reset the conversation history (keeps system prompt and settings).
     */
    public function reset(): static
    {
        $this->messages = [];

        return $this;
    }

    /**
     * Replace the entire message history (used for error recovery).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     */
    public function setMessages(array $messages): static
    {
        $this->messages = $messages;

        return $this;
    }

    /**
     * Append every entry from $messages to the running conversation.
     * Use {@see setMessages()} to replace; use this to re-seed saved history.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     */
    public function history(array $messages): static
    {
        foreach ($messages as $message) {
            $this->messages[] = $message;
        }

        return $this;
    }

    // ─────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Resolve the system prompt actually sent to the model. If a JSON-schema
     * request was attached, append the schema as a JSON-Schema instruction.
     */
    protected function effectiveSystemPrompt(): string
    {
        if ($this->schema === null) {
            return $this->systemPrompt;
        }

        $encoded = json_encode($this->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return rtrim($this->systemPrompt)."\n\n".
            'You MUST respond with a JSON object that conforms to this JSON Schema. '.
            'Output ONLY valid JSON — no prose, no markdown fences:'.
            "\n\n```json\n".$encoded."\n```";
    }

    /**
     * Read a file (or pass through raw base64) and enforce the 15 MB limit.
     */
    protected function readAsBase64(string $source, string $kind): string
    {
        if (is_file($source)) {
            $size = filesize($source);
            if ($size === 0) {
                throw new AiException("{$kind} file is empty: {$source}.");
            }
            if ($size > 15 * 1024 * 1024) {
                throw new AiException(
                    "{$kind} file exceeds 15 MB limit (".round($size / 1024 / 1024, 1).' MB). '.
                    'Reduce the file size first.'
                );
            }
            return base64_encode(file_get_contents($source));
        }

        return $source;
    }

    /**
     * Resolve an image format string — accepts 'auto' (detect from extension)
     * or a canonical name (jpeg, png, gif, webp).
     */
    protected function resolveImageFormat(string $source, string $format): string
    {
        if ($format !== 'auto') {
            return $format;
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'jpeg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            default => 'jpeg',
        };
    }

    /**
     * Resolve a document format string — accepts 'auto' (detect from extension)
     * or a canonical name from the supported set.
     */
    protected function resolveDocumentFormat(string $source, string $format): string
    {
        if ($format !== 'auto') {
            return $format;
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'pdf',
            'csv' => 'csv',
            'doc' => 'doc',
            'docx' => 'docx',
            'xls' => 'xls',
            'xlsx' => 'xlsx',
            'html', 'htm' => 'html',
            'txt', 'text' => 'txt',
            'md', 'markdown' => 'md',
            default => 'pdf',
        };
    }
}
