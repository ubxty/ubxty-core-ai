<?php

namespace Ubxty\CoreAi\Contracts;

/**
 * Token / image counts and cost for an image-generation call.
 *
 * Image-gen billing is per-image, not per-token, but gpt-image-1 / 1.5 / 2
 * still surface input-token usage on the wire so apps that want to track
 * them can. Cost is computed once via {@see \Ubxty\CoreAi\Manager\AbstractAiManager::calculateImageCost()}.
 */
final readonly class ImageUsage
{
    public function __construct(
        public int $imageCount = 1,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
    ) {}
}
