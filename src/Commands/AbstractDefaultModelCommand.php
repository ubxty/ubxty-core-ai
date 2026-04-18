<?php

namespace Ubxty\CoreAi\Commands;

use Illuminate\Console\Command;
use Ubxty\CoreAi\Contracts\AiManagerContract;

/**
 * Abstract default model command.
 */
abstract class AbstractDefaultModelCommand extends Command
{
    use WritesEnvFile;

    protected AiManagerContract $manager;

    abstract protected function platformName(): string;

    /**
     * Return a map of context keys to their .env variable names.
     * e.g. ['default' => 'BEDROCK_DEFAULT_MODEL', 'image' => 'BEDROCK_DEFAULT_IMAGE_MODEL']
     */
    abstract protected function envKeyMap(): array;

    protected function executeDefaultModel(): int
    {
        $connection = $this->option('connection');

        if (! $this->manager->isConfigured($connection)) {
            $this->error($this->platformName().' is not configured. Run the configure command first.');

            return 1;
        }

        $name = $this->platformName();
        $this->newLine();
        $this->info("  {$name} — Set Default Model");
        $this->line('  ─────────────────────────────────────────────');
        $this->newLine();

        $currentDefault = $this->manager->defaultModel();
        $currentImage = $this->manager->defaultImageModel();

        if ($currentDefault) {
            $this->line("  Current default model: <fg=cyan>{$currentDefault}</>");
        }
        if ($currentImage) {
            $this->line("  Current image model:   <fg=cyan>{$currentImage}</>");
        }
        $this->newLine();

        // Determine which context to set
        $envKeyMap = $this->envKeyMap();
        $contexts = array_keys($envKeyMap);

        $context = 'default';
        if (count($contexts) > 1) {
            $context = $this->choice('Which default do you want to set?', $contexts, 0);
        }

        // Option 1: user passed a model ID directly
        $modelId = $this->argument('model');

        if (! $modelId) {
            // Option 2: let user pick from model list
            $modelId = $this->pickModel($connection);
        }

        if (! $modelId) {
            $this->warn('  No model selected.');

            return 0;
        }

        $modelId = $this->manager->resolveAlias($modelId);

        // Test the model before saving
        if ($this->confirm("  Test {$modelId} before saving?", true)) {
            $this->line('  Testing...');

            try {
                $result = $this->manager->invoke(
                    $modelId,
                    'You are a helpful assistant.',
                    'Say hello.',
                    50,
                    0.5,
                    null,
                    $connection,
                );
                $this->info("  ✓ Model responded ({$result['latency_ms']}ms)");
            } catch (\Exception $e) {
                $this->error('  ✗ Model test failed: '.$e->getMessage());
                if (! $this->confirm('  Save anyway?', false)) {
                    return 1;
                }
            }
        }

        $envKey = $envKeyMap[$context] ?? null;

        if ($envKey) {
            $this->writeEnv([$envKey => $modelId]);
            $this->info("  ✓ Saved {$envKey}={$modelId} to .env");
        } else {
            $this->warn("  No .env key mapped for context: {$context}");
        }

        $this->newLine();

        return 0;
    }

    protected function pickModel(?string $connection): ?string
    {
        $this->line('  Fetching models...');

        try {
            $grouped = $this->manager->getModelsGrouped($connection);
        } catch (\Exception $e) {
            $this->error('  Failed to fetch models: '.$e->getMessage());

            return $this->ask('  Enter model ID manually');
        }

        if (empty($grouped)) {
            $this->warn('  No models found. Syncing...');
            $count = $this->manager->syncModels($connection);
            if ($count > 0) {
                $grouped = $this->manager->getModelsGrouped($connection);
            }

            if (empty($grouped)) {
                return $this->ask('  Enter model ID manually');
            }
        }

        $allModels = array_merge(...array_values($grouped));
        $activeModels = array_values(array_filter($allModels, fn ($m) => $m['is_active']));

        if (empty($activeModels)) {
            $activeModels = $allModels;
        }

        $choices = [];
        foreach ($activeModels as $i => $model) {
            $name = $model['name'] ?: $model['model_id'];
            $ctx = number_format($model['context_window'] / 1000).'k';
            $choices[] = "{$name} ({$ctx}) [{$model['model_id']}]";
        }

        $chosen = $this->choice('Select a model', $choices, 0);
        $index = array_search($chosen, $choices, true);

        return $activeModels[$index]['model_id'] ?? null;
    }
}
