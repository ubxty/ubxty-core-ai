<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Provider-neutral options for an image-generation call.
 *
 * Each provider manager translates the relevant subset of fields into its
 * own wire format. Unrecognised fields are ignored (no exception). All
 * fields are optional — providers fall back to their own defaults when
 * a field is null.
 *
 * The persistence-related fields (`disk`, `dir`, `filenamePrefix`,
 * `persist`) are honoured by {@see \Ubxty\CoreAi\Manager\AbstractAiManager}
 * before the call returns, regardless of provider.
 */
final readonly class ImageGenerationOptions
{
    public function __construct(
        public ?int $n = null,
        public ?string $size = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $quality = null,
        public ?string $negativePrompt = null,
        public ?int $seed = null,
        public ?int $steps = null,
        public ?float $cfgScale = null,
        // Image-to-image transformation strength (0.0..1.0). Used by
        // Stability's `mode: image-to-image` variation/edit. Distinct from
        // `cfgScale` (classifier-free guidance), which is a separate dial.
        public ?float $strength = null,
        public ?string $inputFidelity = null,
        public ?string $background = null,
        public ?string $outputFormat = null,
        public ?bool $persist = null,
        public ?string $disk = null,
        public ?string $dir = null,
        public ?string $filenamePrefix = null,
        public array $metadata = [],
    ) {}
}
