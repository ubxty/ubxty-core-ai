<?php

namespace Ubxty\CoreAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\CoreAi\Contracts\ImageResult;
use Ubxty\CoreAi\Contracts\ImageUsage;

class ImageResultTest extends TestCase
{
    public function test_minimal_construction(): void
    {
        $result = new ImageResult(bytes: 'PNGDATA', mimeType: 'image/png', modelId: 'gpt-image-1');

        $this->assertSame('PNGDATA', $result->bytes);
        $this->assertSame('image/png', $result->mimeType);
        $this->assertSame('gpt-image-1', $result->modelId);
        $this->assertNull($result->revisedPrompt);
        $this->assertNull($result->keyLabel);
        $this->assertSame(0, $result->latencyMs);
        $this->assertFalse($result->cached);
        $this->assertSame([], $result->raw);
        $this->assertNull($result->usage);
    }

    public function test_full_construction(): void
    {
        $usage = new ImageUsage(imageCount: 1, inputTokens: 100, outputTokens: 50, cost: 0.04);
        $result = new ImageResult(
            bytes: 'BIN',
            mimeType: 'image/webp',
            modelId: 'amazon.nova-canvas-v1:0',
            revisedPrompt: 'a foggy street',
            keyLabel: 'primary',
            latencyMs: 2200,
            cached: true,
            raw: ['finish_reasons' => ['SUCCESS']],
            usage: $usage,
        );

        $this->assertSame('a foggy street', $result->revisedPrompt);
        $this->assertSame('primary', $result->keyLabel);
        $this->assertSame(2200, $result->latencyMs);
        $this->assertTrue($result->cached);
        $this->assertSame(['finish_reasons' => ['SUCCESS']], $result->raw);
        $this->assertSame($usage, $result->usage);
    }
}
