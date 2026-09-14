<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Wire-format-neutral image-generation result envelope.
 *
 * Mirrors {@see LLMResult} but for image-output models. Each provider
 * produces one of these from its wire format (Bedrock InvokeModel JSON
 * for Stable Image / Nova Canvas / Titan Image / SD3.5; Azure OpenAI
 * `/images/generations` and `/images/edits`).
 *
 * `bytes` is always raw binary (decoded from base64 if the source
 * sent base64). `mimeType` is a real MIME (`image/png` etc.). The
 * manager layer is responsible for any persistence via
 * {@see \Ubxty\CoreAi\Support\ImagePersistence::persist()}.
 */
final readonly class ImageResult
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $modelId,
        public ?string $revisedPrompt = null,
        public ?string $keyLabel = null,
        public int $latencyMs = 0,
        public bool $cached = false,
        public array $raw = [],
        public ?ImageUsage $usage = null,
    ) {}
}
