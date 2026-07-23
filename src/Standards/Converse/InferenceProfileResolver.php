<?php

namespace Ubxty\CoreAi\Standards\Converse;

/**
 * Resolve cross-region inference profile prefixes for AWS Bedrock models.
 *
 * Some Bedrock models (notably Anthropic Claude 3.5/4 and Amazon Nova on
 * older regions) only accept their model_id prefixed with the regional
 * inference-profile tag: `us.`, `eu.`, `apac.`, or `ca.`. The prefix is
 * added or stripped depending on whether the resolved region already
 * supports the model natively.
 *
 * This class is intentionally pure (no IO, no SDK deps) so it can be
 * unit-tested in isolation and called from both the core-ai ConverseClient
 * and the bedrock-ai BedrockClient override.
 *
 * Duplicated from `bedrock-ai/src/Client/InferenceProfileResolver.php`
 * for core-ai to stay self-contained. Bedrock-ai's copy will be deleted
 * in Wave 2; bedrock-ai's BedrockClient will then import this one via
 * `extends core-ai/Standards/Converse/ConverseClient`.
 */
class InferenceProfileResolver
{
    /** @var array<string, array<int, string>> */
    private const NATIVE_REGION_PREFIXES = [
        'us-east-1'  => [],
        'us-west-2'  => [],
        'eu-west-1'  => [],
        'eu-west-3'  => [],
        'eu-central-1' => [],
        'ap-northeast-1' => [],
        'ap-northeast-2' => [],
        'ap-southeast-1' => [],
        'ap-southeast-2' => [],
        'ca-central-1'   => [],
    ];

    /**
     * Strip any leading cross-region inference profile prefix from the model_id.
     *
     * `us.anthropic.claude-3-5-sonnet-…` → `anthropic.claude-3-5-sonnet-…`
     */
    public static function stripPrefix(string $modelId): string
    {
        return preg_replace('/^(?:us|eu|apac|ca)\./', '', $modelId) ?? $modelId;
    }

    /**
     * Determine the wire-level model_id for the given (logical) model_id + region.
     *
     * - If the region natively supports the model, returns `$modelId` unchanged.
     * - Otherwise prefixes the model_id with the regional inference-profile tag.
     * - If `$modelId` already carries a prefix, the prefix is replaced (not stacked).
     */
    public static function resolve(string $modelId, string $region): string
    {
        $bare = self::stripPrefix($modelId);

        // Native regions: no prefix needed.
        if (array_key_exists($region, self::NATIVE_REGION_PREFIXES)) {
            return $bare;
        }

        // Non-native regions: choose the closest matching prefix.
        $prefix = self::prefixForRegion($region);

        return $prefix . $bare;
    }

    /**
     * Pick the inference-profile prefix for a region.
     *
     * - Regions in the Americas (us-*, ca-*, sa-*, mx-*) → 'us.'
     * - Regions in Europe / Middle East / Africa → 'eu.'
     * - Asia-Pacific regions → 'apac.'
     * - Canadian regions → 'ca.'
     */
    private static function prefixForRegion(string $region): string
    {
        $lower = strtolower($region);

        if (str_starts_with($lower, 'eu-')
            || str_starts_with($lower, 'af-')
            || str_starts_with($lower, 'me-')) {
            return 'eu.';
        }

        if (str_starts_with($lower, 'ap-')
            || str_starts_with($lower, 'cn-')) {
            return 'apac.';
        }

        if (str_starts_with($lower, 'ca-')) {
            return 'ca.';
        }

        return 'us.';
    }
}