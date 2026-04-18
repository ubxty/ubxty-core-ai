<?php

namespace Ubxty\CoreAi\Commands;

use Illuminate\Console\Command;
use Ubxty\CoreAi\Contracts\AiManagerContract;

/**
 * Abstract connection test command.
 */
abstract class AbstractTestCommand extends Command
{
    protected AiManagerContract $manager;

    abstract protected function platformName(): string;

    protected function executeTest(): int
    {
        $connection = $this->option('connection');
        $modelId = $this->argument('model');

        if (! $this->manager->isConfigured($connection)) {
            $this->error($this->platformName().' is not configured. Run the configure command first.');

            return 1;
        }

        $name = $this->platformName();
        $this->newLine();
        $this->line('  <fg=cyan>╔═══════════════════════════════════════════╗</>');
        $this->line("  <fg=cyan>║   {$name} Connection Test".str_repeat(' ', max(0, 21 - strlen($name))).'║</>');
        $this->line('  <fg=cyan>╚═══════════════════════════════════════════╝</>');
        $this->newLine();

        $this->line('  <options=bold>Testing connection...</>');
        $result = $this->manager->testConnection($connection);

        if (! $result['success']) {
            $this->error('  ✗ Connection failed: '.$result['message']);

            return 1;
        }

        $this->info('  ✓ '.$result['message']);
        $this->line("  Response time: {$result['response_time']}ms");
        $this->newLine();

        if ($this->option('all-keys')) {
            $this->testAllKeys($connection);
        }

        if ($modelId) {
            return $this->testModel($connection, $modelId);
        }

        if (! $this->confirm('  Test a model invocation?', false)) {
            return 0;
        }

        if ($this->option('sync')) {
            $this->line('  Syncing models to database...');
            $count = $this->manager->syncModels($connection);
            $this->info("  ✓ Synced {$count} models.");
            $this->newLine();
        }

        $modelId = $this->pickModel($connection);

        if (! $modelId) {
            return 0;
        }

        return $this->testModel($connection, $modelId);
    }

    protected function pickModel(?string $connection): ?string
    {
        $this->line('  <options=bold>Fetching available models...</>');
        $showLegacy = $this->option('legacy');

        try {
            $grouped = $this->manager->getModelsGrouped($connection);
        } catch (\Throwable $e) {
            $this->error('  ✗ Could not load models: '.$e->getMessage());

            return $this->ask('  Enter model ID manually');
        }

        if (empty($grouped)) {
            $this->warn('  No models found.');

            if ($this->confirm('  Sync models now?', true)) {
                $this->line('  Syncing...');
                try {
                    $count = $this->manager->syncModels($connection);
                    if ($count > 0) {
                        $this->info("  ✓ Synced {$count} models.");
                        $grouped = $this->manager->getModelsGrouped($connection);
                    } else {
                        $this->warn('  Sync returned 0 models.');
                    }
                } catch (\Throwable $e) {
                    $this->error('  Sync failed: '.$e->getMessage());
                }
            }

            if (empty($grouped)) {
                return $this->ask('  Enter model ID manually');
            }
        }

        if (! $showLegacy) {
            foreach ($grouped as $provider => $models) {
                $grouped[$provider] = array_values(
                    array_filter($models, fn ($m) => $m['is_active'])
                );
            }
            $grouped = array_filter($grouped, fn ($models) => ! empty($models));
        }

        $totalModels = array_sum(array_map('count', $grouped));
        $this->info("  Found {$totalModels} models across ".count($grouped).' providers.');
        $this->newLine();

        // Step 1: choose provider
        $providers = array_keys($grouped);
        $providerChoices = array_map(function (string $provider) use ($grouped) {
            return "{$provider} (".count($grouped[$provider]).' models)';
        }, $providers);

        $providerLabel = $this->choice('  Select a provider', $providerChoices, 0);
        $providerIndex = array_search($providerLabel, $providerChoices, true);
        $provider = $providers[$providerIndex];
        $models = $grouped[$provider];

        // Step 2: choose model
        $this->newLine();

        $nameCounts = array_count_values(array_column($models, 'name'));
        $modelChoices = array_map(function (array $model) use ($nameCounts) {
            $ctx = number_format($model['context_window'] / 1000).'k';
            $label = "{$model['name']} — {$ctx} context";

            $inputs = $model['input_modalities'] ?? ['text'];
            $tags = [];
            if (in_array('image', $inputs, true)) {
                $tags[] = 'img';
            }
            if (in_array('document', $inputs, true)) {
                $tags[] = 'pdf';
            }
            if (! empty($tags)) {
                $label .= '  ['.implode(', ', $tags).']';
            }

            if ($nameCounts[$model['name']] > 1) {
                $shortId = preg_replace('/^[^.]+\./', '', $model['model_id']);
                $label .= "  ({$shortId})";
            }

            return $label;
        }, $models);

        $chosen = $this->choice('  Select a model', $modelChoices, 0);
        $modelIndex = array_search($chosen, $modelChoices, true);
        $selected = $models[$modelIndex];

        $this->line("  <fg=gray>Model ID: {$selected['model_id']}</>");

        return $selected['model_id'];
    }

    protected function testModel(?string $connection, string $modelId): int
    {
        $prompt = $this->option('prompt') ?? 'Say hello in exactly 3 words.';
        $maxTokens = (int) $this->option('max-tokens');

        $this->newLine();
        $this->line("  <options=bold>Invoking:</> {$modelId}");
        $this->line("  <options=bold>Prompt:</> \"{$prompt}\"");
        $this->newLine();

        try {
            $result = $this->manager->invoke(
                $modelId,
                'You are a helpful assistant. Respond briefly.',
                $prompt,
                $maxTokens,
                0.5,
                null,
                $connection,
            );

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));

                return 0;
            }

            $this->info('  ✓ Model responded successfully!');
            $this->newLine();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Response', wordwrap($result['response'], 80, "\n", true)],
                    ['Input Tokens', number_format($result['input_tokens'])],
                    ['Output Tokens', number_format($result['output_tokens'])],
                    ['Total Tokens', number_format($result['total_tokens'])],
                    ['Cost', '$'.number_format($result['cost'], 6)],
                    ['Latency', $result['latency_ms'].'ms'],
                    ['Key Used', $result['key_used']],
                    ['Model ID', $result['model_id']],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error('  ✗ Invocation failed: '.$e->getMessage());

            return 1;
        }
    }

    protected function testAllKeys(?string $connection): void
    {
        $this->line('  <options=bold>Testing all credential keys...</>');
        $this->newLine();

        try {
            $keys = $this->manager->getCredentialInfo($connection);
            $results = [];

            foreach ($keys as $key) {
                $results[] = [
                    $key['label'],
                    $key['region'] ?? '-',
                    $key['configured'] ? '✓ Configured' : '✗ Missing',
                    '-',
                ];
            }

            $this->table(['Key', 'Region', 'Status', 'Details'], $results);
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('  Error testing keys: '.$e->getMessage());
        }
    }
}
