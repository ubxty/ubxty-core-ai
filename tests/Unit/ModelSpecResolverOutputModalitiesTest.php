<?php

namespace Ubxty\CoreAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\CoreAi\Models\ModelSpecResolver;

class ModelSpecResolverOutputModalitiesTest extends TestCase
{
    /**
     * @dataProvider imageGenModelProvider
     */
    public function test_image_gen_models_report_image_output(string $modelId): void
    {
        $this->assertSame(['image'], ModelSpecResolver::outputModalities($modelId));
        $this->assertTrue(ModelSpecResolver::supportsOutputModality($modelId, 'image'));
        $this->assertFalse(ModelSpecResolver::supportsOutputModality($modelId, 'audio'));
    }

    public static function imageGenModelProvider(): array
    {
        return [
            // OpenAI / Azure OpenAI
            'gpt-image-2'        => ['gpt-image-2'],
            'gpt-image-1.5'      => ['gpt-image-1.5'],
            'gpt-image-1'        => ['gpt-image-1'],
            'gpt-image-1-mini'   => ['gpt-image-1-mini'],

            // AWS Bedrock
            'amazon.nova-canvas' => ['amazon.nova-canvas-v1:0'],
            'amazon.titan-image' => ['amazon.titan-image-generator-v2:0'],
            'stability.stable-image-core' => ['stability.stable-image-core-v1:1'],
            'stability.stable-image-ultra' => ['stability.stable-image-ultra-v1:1'],
            'stability.sd3.5-large' => ['stability.sd3-5-large-v1:0'],

            // Azure FLUX
            'flux.2-pro' => ['flux.2-pro'],
            'flux.2-flex' => ['flux.2-flex'],
            'flux-1-kontext-pro' => ['flux-1-kontext-pro'],

            // Azure MAI
            'mai-image-2.6' => ['mai-image-2.6'],
            'mai-image-2.6-flash' => ['mai-image-2.6-flash'],
            'mai-image-2.5-pro' => ['mai-image-2.5-pro'],

            // Luma
            'luma-ray-v2' => ['luma.ray-v2:0'],

            // Legacy
            'dall-e-3' => ['dall-e-3'],
        ];
    }

    /**
     * @dataProvider textOnlyModelProvider
     */
    public function test_text_only_models_report_text_output(string $modelId): void
    {
        $this->assertSame(['text'], ModelSpecResolver::outputModalities($modelId));
        $this->assertFalse(ModelSpecResolver::supportsOutputModality($modelId, 'image'));
    }

    public static function textOnlyModelProvider(): array
    {
        return [
            'claude-sonnet-4.5' => ['claude-sonnet-4-5-20250929'],
            'gpt-5' => ['gpt-5'],
            'gpt-4o' => ['gpt-4o'],
            'gemini-2.5-pro' => ['gemini-2.5-pro'],
            'amazon.nova-pro' => ['amazon.nova-pro-v1:0'],
        ];
    }
}
