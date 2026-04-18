<?php

namespace Ubxty\CoreAi\Commands;

use Illuminate\Console\Command;
use Ubxty\CoreAi\Contracts\AiManagerContract;

/**
 * Abstract interactive chat command.
 *
 * Concrete commands set $signature, $description, and implement handle()
 * to inject their platform-specific manager, then call executeChat().
 */
abstract class AbstractChatCommand extends Command
{
    protected AiManagerContract $manager;

    protected int $totalInputTokens = 0;

    protected int $totalOutputTokens = 0;

    protected float $totalCost = 0;

    protected int $messageCount = 0;

    abstract protected function platformName(): string;

    protected function executeChat(): int
    {
        $connection = $this->option('connection');

        if (! $this->manager->isConfigured($connection)) {
            $this->error($this->platformName().' is not configured. Run the configure command first.');

            return 1;
        }

        $modelId = $this->argument('model');

        if (! $modelId) {
            $defaultModel = $this->manager->defaultModel();

            if ($defaultModel) {
                $this->line("  Default model: <fg=cyan>{$defaultModel}</>");
                $useDefault = $this->confirm('  Use default model?', true);

                if ($useDefault) {
                    $modelId = $defaultModel;
                } else {
                    $modelId = $this->selectModel($connection);
                }
            } else {
                $modelId = $this->selectModel($connection);
            }

            if (! $modelId) {
                return 1;
            }
        }

        $modelId = $this->manager->resolveAlias($modelId);
        $systemPrompt = $this->option('system') ?? 'You are a helpful AI assistant.';
        $maxTokens = (int) $this->option('max-tokens');
        $temperature = (float) $this->option('temperature');
        $useStreaming = ! $this->option('no-stream');

        $this->printHeader($modelId, $systemPrompt, $useStreaming);

        $conversation = $this->manager->conversation($modelId)
            ->system($systemPrompt)
            ->maxTokens($maxTokens)
            ->temperature($temperature);

        if ($connection) {
            $conversation->connection($connection);
        }

        while (true) {
            $this->newLine();
            $input = $this->ask('<fg=green>You</>');

            if ($input === null || $input === '') {
                continue;
            }

            $command = strtolower(trim($input));

            if (in_array($command, ['/quit', '/exit', '/q'])) {
                break;
            }

            if ($command === '/help') {
                $this->printHelp();

                continue;
            }

            if ($command === '/stats') {
                $this->printStats();

                continue;
            }

            if ($command === '/reset') {
                $conversation->reset();
                $this->totalInputTokens = 0;
                $this->totalOutputTokens = 0;
                $this->totalCost = 0;
                $this->messageCount = 0;
                $this->info('  Conversation reset.');

                continue;
            }

            if (str_starts_with($command, '/system ')) {
                $newSystem = substr($input, 8);
                $conversation->system($newSystem);
                $this->info('  System prompt updated.');

                continue;
            }

            if (str_starts_with($command, '/model ')) {
                $newModel = trim(substr($input, 7));
                $newModel = $this->manager->resolveAlias($newModel);
                $conversation = $this->manager->conversation($newModel)
                    ->system($conversation->getSystemPrompt())
                    ->maxTokens($maxTokens)
                    ->temperature($temperature);

                if ($connection) {
                    $conversation->connection($connection);
                }

                $modelId = $newModel;
                $this->info("  Switched to model: {$newModel}");

                continue;
            }

            if (str_starts_with($command, '/temp ')) {
                $newTemp = (float) trim(substr($input, 6));
                $newTemp = max(0.0, min(1.0, $newTemp));
                $conversation->temperature($newTemp);
                $temperature = $newTemp;
                $this->info("  Temperature set to: {$newTemp}");

                continue;
            }

            if (str_starts_with($command, '/image ')) {
                $this->handleFileCommand(
                    $input, 7, 'image',
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    'Describe this image in detail.',
                    $conversation, $connection, $useStreaming,
                );

                continue;
            }

            if (str_starts_with($command, '/doc ')) {
                $this->handleFileCommand(
                    $input, 5, 'document',
                    ['pdf', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'html', 'htm', 'txt', 'md'],
                    'Summarize this document.',
                    $conversation, $connection, $useStreaming,
                );

                continue;
            }

            $conversation->user($input);

            try {
                $this->line('');
                $this->line('  <fg=cyan>Assistant</>');

                if ($useStreaming && $this->manager->supportsStreaming($connection)) {
                    $result = $this->sendStreaming($conversation);
                } else {
                    $result = $this->sendBlocking($conversation);
                }

                $this->messageCount++;
                $this->totalInputTokens += $result['input_tokens'] ?? 0;
                $this->totalOutputTokens += $result['output_tokens'] ?? 0;
                $this->totalCost += $result['cost'] ?? 0;

                $this->newLine();
                $this->line(sprintf(
                    '  <fg=gray>[%d in / %d out / %dms]</>',
                    $result['input_tokens'] ?? 0,
                    $result['output_tokens'] ?? 0,
                    $result['latency_ms'] ?? 0,
                ));
            } catch (\Exception $e) {
                $messages = $conversation->getMessages();
                array_pop($messages);
                $conversation->reset()->setMessages($messages);

                $this->error('  Error: '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->printStats();
        $this->info('  Goodbye!');
        $this->newLine();

        return 0;
    }

    protected function selectModel(?string $connection): ?string
    {
        $this->info('Fetching available models...');

        try {
            $grouped = $this->manager->getModelsGrouped($connection, 'chat');
        } catch (\Exception $e) {
            $this->error('Failed to fetch models: '.$e->getMessage());

            return null;
        }

        if (empty($grouped)) {
            $this->error('No models available.');

            return null;
        }

        $allModels = array_merge(...array_values($grouped));
        $textModels = array_values(array_filter($allModels, function ($m) {
            return $m['is_active'] && in_array('text', $m['capabilities'] ?? []);
        }));

        if (empty($textModels)) {
            $textModels = $allModels;
        }

        $this->newLine();
        $this->info('  Available Models');
        $this->line('  ─────────────────────────────────────────────');

        $choices = [];
        foreach ($textModels as $i => $model) {
            $num = $i + 1;
            $name = $model['name'] ?: $model['model_id'];
            $ctx = number_format($model['context_window']);
            $this->line(sprintf('  <fg=yellow>%3d</> │ %s <fg=gray>(%s ctx)</>',
                $num, $name, $ctx
            ));
            $choices[$num] = $model['model_id'];
        }

        $customNum = count($textModels) + 1;
        $this->line(sprintf('  <fg=yellow>%3d</> │ <fg=gray>Other (enter custom model ID)</>', $customNum));

        $this->newLine();
        $selection = $this->ask('Select a model (number or ID)');

        if (! $selection) {
            return null;
        }

        // "Other" option — prompt for the custom model ID
        if (is_numeric($selection) && (int) $selection === $customNum) {
            $custom = $this->ask('Enter model ID');

            return $custom ?: null;
        }

        if (is_numeric($selection) && isset($choices[(int) $selection])) {
            return $choices[(int) $selection];
        }

        return $selection;
    }

    protected function handleFileCommand(
        string $input,
        int $prefixLen,
        string $type,
        array $allowedExtensions,
        string $defaultPrompt,
        $conversation,
        ?string $connection,
        bool $useStreaming,
    ): void {
        $args = trim(substr($input, $prefixLen));
        $filePath = null;
        $prompt = $defaultPrompt;

        if (preg_match('/^("(?<quoted>[^"]+)"|(?<bare>\S+))\s*(?<prompt>.*)$/s', $args, $m)) {
            $filePath = $m['quoted'] ?: $m['bare'];
            if (! empty(trim($m['prompt']))) {
                $prompt = trim($m['prompt']);
            }
        }

        if (! $filePath || ! is_file($filePath)) {
            $this->error('  File not found: '.($filePath ?: '(none)'));
            $this->line("  <fg=gray>Usage: /{$type} /path/to/file [optional prompt]</>");

            return;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (! in_array($ext, $allowedExtensions)) {
            $this->error("  Unsupported {$type} format: .{$ext}");
            $this->line('  <fg=gray>Supported: '.implode(', ', $allowedExtensions).'</>');

            return;
        }

        $sizeKb = round(filesize($filePath) / 1024);
        $label = $type === 'image' ? 'image' : 'document';
        $this->line("  <fg=gray>Sending {$label}: {$filePath} ({$sizeKb} KB)</>");

        try {
            if ($type === 'image') {
                $conversation->userWithImage($prompt, $filePath);
            } else {
                $conversation->userWithDocument($prompt, $filePath);
            }

            $this->line('');
            $this->line('  <fg=cyan>Assistant</>');

            if ($useStreaming && $this->manager->supportsStreaming($connection)) {
                $result = $this->sendStreaming($conversation);
            } else {
                $result = $this->sendBlocking($conversation);
            }

            $this->messageCount++;
            $this->totalInputTokens += $result['input_tokens'] ?? 0;
            $this->totalOutputTokens += $result['output_tokens'] ?? 0;
            $this->totalCost += $result['cost'] ?? 0;

            $this->newLine();
            $this->line(sprintf(
                '  <fg=gray>[%d in / %d out / %dms]</>',
                $result['input_tokens'] ?? 0,
                $result['output_tokens'] ?? 0,
                $result['latency_ms'] ?? 0,
            ));
        } catch (\Exception $e) {
            $messages = $conversation->getMessages();
            array_pop($messages);
            $conversation->reset()->setMessages($messages);

            $this->error('  Error: '.$e->getMessage());
        }
    }

    protected function sendStreaming($conversation): array
    {
        return $conversation->sendStream(function (string $chunk) {
            $this->output->write($chunk);
        });
    }

    protected function sendBlocking($conversation): array
    {
        $result = $conversation->send();
        $this->line('  '.$result['response']);

        return $result;
    }

    protected function printHeader(string $modelId, string $systemPrompt, bool $streaming): void
    {
        $name = $this->platformName();
        $this->info('');
        $this->info('  ╔═══════════════════════════════════════════╗');
        $this->info("  ║   {$name} Chat Session".str_repeat(' ', max(0, 26 - strlen($name))).'║');
        $this->info('  ╚═══════════════════════════════════════════╝');
        $this->info('');
        $this->line("  Model:     <fg=cyan>{$modelId}</>");
        $this->line('  System:    <fg=gray>'.substr($systemPrompt, 0, 60).(strlen($systemPrompt) > 60 ? '...' : '').'</>');
        $this->line('  Streaming: '.($streaming ? '<fg=green>On</>' : '<fg=yellow>Off</>'));
        $this->line('');
        $this->line('  Type your message and press Enter. Commands:');
        $this->line('  <fg=yellow>/help</> - Show all commands  <fg=yellow>/quit</> - Exit session');
        $this->line('  ─────────────────────────────────────────────');
    }

    protected function printHelp(): void
    {
        $this->newLine();
        $this->info('  Chat Commands');
        $this->line('  ─────────────────────────────────────────────');
        $this->line('  <fg=yellow>/quit</>           Exit the chat session');
        $this->line('  <fg=yellow>/help</>           Show this help message');
        $this->line('  <fg=yellow>/stats</>          Show session statistics');
        $this->line('  <fg=yellow>/reset</>          Clear conversation history');
        $this->line('  <fg=yellow>/system <text></>  Change the system prompt');
        $this->line('  <fg=yellow>/model <id></>     Switch to a different model');
        $this->line('  <fg=yellow>/temp <0-1></>     Change temperature');
        $this->line('  <fg=yellow>/image <path> [prompt]</>');
        $this->line('                    Analyse an image (jpg/png/gif/webp)');
        $this->line('  <fg=yellow>/doc <path> [prompt]</>');
        $this->line('                    Analyse a document (pdf/csv/docx/xlsx/html/txt/md)');
    }

    protected function printStats(): void
    {
        $this->info('  Session Statistics');
        $this->line('  ─────────────────────────────────────────────');
        $this->line("  Messages:      {$this->messageCount}");
        $this->line('  Input Tokens:  '.number_format($this->totalInputTokens));
        $this->line('  Output Tokens: '.number_format($this->totalOutputTokens));
        $this->line('  Total Tokens:  '.number_format($this->totalInputTokens + $this->totalOutputTokens));

        if ($this->totalCost > 0) {
            $this->line('  Estimated Cost: $'.number_format($this->totalCost, 6));
        }
    }
}
