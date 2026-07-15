<?php

namespace Ubxty\CoreAi\Commands\Concerns;

/**
 * Shared paste-spool behaviour for interactive chat commands.
 *
 * When the user pastes something larger than the configured byte or
 * line threshold (default 2 KiB / 50 lines), the raw text gets written
 * to sys_get_temp_dir() and the message that goes to the model is
 * replaced with a short path reference. The terminal stays clean and
 * the model gets the file path to read on its own — same pattern as
 * Claude Code's `@`-file references.
 *
 * Pastes are deduplicated by content hash within a session so a repeat
 * paste (or a model echoing one back through the conversation) reuses
 * the same temp file. /reset sweeps them; /quit leaves them on disk so
 * the user can grep them after the session ends.
 */
trait PasteSpooler
{
    /** Threshold in bytes. Pastes larger than this get spooled. */
    protected int $pasteSpoolByteThreshold = 2048;

    /** Threshold in lines. Pastes with more newlines than this get spooled. */
    protected int $pasteSpoolLineThreshold = 50;

    /** Temp files written by the current session, keyed by absolute path. */
    protected array $spooledPastes = [];

    /**
     * Whether the user's input is large enough to spool. Triggers on byte
     * size OR line count — a 500-line 1 KB paste is just as painful as a
     * single 50 KB blob.
     */
    protected function shouldSpoolPaste(string $input): bool
    {
        $bytes = strlen($input);
        $lines = substr_count($input, "\n") + 1;

        return $bytes > $this->pasteSpoolByteThreshold
            || $lines > $this->pasteSpoolLineThreshold;
    }

    /**
     * Write the paste to a temp file and return metadata + a path reference
     * the model can act on. Filename uses a short xxh64 hash of the
     * contents so a repeat paste within a session reuses the same file.
     *
     * @return array{path: string, bytes: int, lines: int, reference: string}
     */
    protected function spoolPaste(string $input): array
    {
        $hash = substr(hash('xxh64', $input), 0, 10);
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'ubxty-chat-'.$hash.'.txt';

        if (! is_file($path)) {
            // 0600 — paste contents are private to this user.
            file_put_contents($path, $input, LOCK_EX);
            @chmod($path, 0600);
        }

        $bytes = strlen($input);
        $lines = substr_count($input, "\n") + 1;
        $sizeKb = round($bytes / 1024, 1);

        $this->spooledPastes[$path] = true;

        $reference = sprintf(
            '[User pasted %s (%d lines, %s KB). Full contents at: %s — read the file for context.]',
            $this->formatBytes($bytes),
            $lines,
            $sizeKb,
            $path
        );

        return [
            'path'      => $path,
            'bytes'     => $bytes,
            'lines'     => $lines,
            'reference' => $reference,
        ];
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 2).' MB';
    }

    /**
     * Remove every spooled paste from /tmp. Called on /reset so a long
     * session doesn't leave a graveyard of files. /quit skips cleanup by
     * design — the user might want to grep the pastes later, and the
     * host should sweep /tmp via tmpfiles.d anyway.
     */
    protected function cleanupSpooledPastes(): int
    {
        $removed = 0;
        foreach (array_keys($this->spooledPastes) as $path) {
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
            unset($this->spooledPastes[$path]);
        }

        return $removed;
    }
}