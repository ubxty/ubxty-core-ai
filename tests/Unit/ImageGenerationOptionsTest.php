<?php

namespace Ubxty\CoreAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\CoreAi\Contracts\ImageGenerationOptions;

class ImageGenerationOptionsTest extends TestCase
{
    public function test_default_construction_is_all_null(): void
    {
        $opts = new ImageGenerationOptions();

        $this->assertNull($opts->n);
        $this->assertNull($opts->size);
        $this->assertNull($opts->width);
        $this->assertNull($opts->height);
        $this->assertNull($opts->quality);
        $this->assertNull($opts->negativePrompt);
        $this->assertNull($opts->seed);
        $this->assertNull($opts->steps);
        $this->assertNull($opts->cfgScale);
        $this->assertNull($opts->inputFidelity);
        $this->assertNull($opts->background);
        $this->assertNull($opts->outputFormat);
        $this->assertNull($opts->persist);
        $this->assertNull($opts->disk);
        $this->assertNull($opts->dir);
        $this->assertNull($opts->filenamePrefix);
        $this->assertSame([], $opts->metadata);
    }

    public function test_full_construction_round_trip(): void
    {
        $opts = new ImageGenerationOptions(
            n: 2,
            size: '1024x1024',
            quality: 'high',
            negativePrompt: 'blurry',
            seed: 42,
            steps: 30,
            cfgScale: 7.5,
            inputFidelity: 'high',
            background: 'transparent',
            outputFormat: 'png',
            persist: true,
            disk: 's3',
            dir: 'gallery',
            filenamePrefix: 'hero',
            metadata: ['campaign' => 'launch-q3'],
        );

        $this->assertSame(2, $opts->n);
        $this->assertSame('1024x1024', $opts->size);
        $this->assertSame('high', $opts->quality);
        $this->assertSame('blurry', $opts->negativePrompt);
        $this->assertSame(42, $opts->seed);
        $this->assertSame(30, $opts->steps);
        $this->assertSame(7.5, $opts->cfgScale);
        $this->assertSame('high', $opts->inputFidelity);
        $this->assertSame('transparent', $opts->background);
        $this->assertSame('png', $opts->outputFormat);
        $this->assertTrue($opts->persist);
        $this->assertSame('s3', $opts->disk);
        $this->assertSame('gallery', $opts->dir);
        $this->assertSame('hero', $opts->filenamePrefix);
        $this->assertSame(['campaign' => 'launch-q3'], $opts->metadata);
    }
}
