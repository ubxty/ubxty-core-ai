<?php

namespace Ubxty\CoreAi\Commands;

use Illuminate\Console\Command;
use Ubxty\CoreAi\Contracts\AiManagerContract;

/**
 * Abstract configure command.
 *
 * Provides a shared wizard flow for platform configuration.
 * Concrete commands override platformName(), envPrefix(),
 * and requiredEnvKeys() to tailor the setup steps.
 */
abstract class AbstractConfigureCommand extends Command
{
    use WritesEnvFile;

    protected AiManagerContract $manager;

    abstract protected function platformName(): string;

    /**
     * Return the env prefix (e.g. 'BEDROCK', 'AZURE_OPENAI').
     */
    abstract protected function envPrefix(): string;

    /**
     * Return the list of env variable keys that must be configured.
     *
     * @return array<int, array{key: string, label: string, secret: bool, hint?: string}>
     */
    abstract protected function requiredEnvKeys(): array;

    protected function executeConfigure(): int
    {
        $name = $this->platformName();
        $this->newLine();
        $this->info("  {$name} Configuration Wizard");
        $this->line('  ─────────────────────────────────────────────');
        $this->newLine();

        if ($this->option('show')) {
            $this->showCurrentConfig();

            return 0;
        }

        $envValues = [];

        foreach ($this->requiredEnvKeys() as $spec) {
            $currentValue = env($spec['key']);
            $masked = $spec['secret'] && $currentValue
                ? substr($currentValue, 0, 4).str_repeat('*', max(0, strlen($currentValue) - 8)).substr($currentValue, -4)
                : ($currentValue ?: '(not set)');

            $this->line("  <fg=yellow>{$spec['label']}</>");
            $this->line("  Current: <fg=gray>{$masked}</>");

            if (! empty($spec['hint'])) {
                $this->line("  Hint: <fg=gray>{$spec['hint']}</>");
            }

            $value = $spec['secret']
                ? $this->secret("  Enter {$spec['label']}")
                : $this->ask("  Enter {$spec['label']}", $currentValue ?: null);

            if ($value !== null && $value !== '') {
                $envValues[$spec['key']] = $value;
            }

            $this->newLine();
        }

        if (! empty($envValues)) {
            $this->writeEnv($envValues);
            $this->info('  ✓ Configuration saved to .env');
        } else {
            $this->info('  No changes made.');
        }

        $this->newLine();

        if ($this->confirm('  Test the connection now?', true)) {
            try {
                $result = $this->manager->testConnection();
                $this->info('  ✓ '.($result['message'] ?? 'Connection successful'));
            } catch (\Exception $e) {
                $this->error('  ✗ Connection failed: '.$e->getMessage());
            }
        }

        $this->newLine();

        return 0;
    }

    protected function showCurrentConfig(): void
    {
        $name = $this->platformName();
        $this->info("  {$name} Current Configuration");
        $this->line('  ─────────────────────────────────────────────');
        $this->newLine();

        foreach ($this->requiredEnvKeys() as $spec) {
            $value = env($spec['key']);

            if (! $value) {
                $display = '<fg=red>(not set)</>';
            } elseif ($spec['secret']) {
                $display = '<fg=green>'.substr($value, 0, 4).str_repeat('*', max(0, strlen($value) - 8)).substr($value, -4).'</>';
            } else {
                $display = "<fg=green>{$value}</>";
            }

            $this->line("  {$spec['label']}: {$display}");
        }

        $this->newLine();
        $this->line('  Configured: '.($this->manager->isConfigured() ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->newLine();
    }
}
