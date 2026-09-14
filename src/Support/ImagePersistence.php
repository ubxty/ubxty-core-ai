<?php

namespace Ubxty\CoreAi\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves image bytes to a Laravel storage disk and returns the public URL.
 *
 * Used by {@see \Ubxty\CoreAi\Manager\AbstractAiManager} after every
 * `generateImage / editImage / variationImage` call. The manager always
 * invokes this helper before returning to caller code; the only way to
 * skip persistence is `$options->persist === false`.
 *
 * Disk and dir come from the call-site override, falling back to
 * `config('core-ai.storage.disk')` and `config('core-ai.storage.dir')`.
 *
 * Filenames are `{prefix}-{ulid}.{ext}`. The ULID keeps lexicographic
 * ordering aligned with creation time so a directory listing is a
 * chronological gallery.
 */
class ImagePersistence
{
    public const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * @return array{path: string, url: ?string, disk: string, filename: string}
     */
    public static function persist(
        string $bytes,
        string $mimeType,
        ?string $disk = null,
        ?string $dir = null,
        ?string $prefix = null,
    ): array {
        $mimeType = strtolower(trim($mimeType));

        if (! in_array($mimeType, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException(
                "Image MIME type not supported: {$mimeType}. Allowed: "
                . implode(', ', self::ALLOWED_MIMES)
            );
        }

        $decoded = self::decodeIfBase64($bytes);

        $disk = $disk ?: (string) config('core-ai.storage.disk', 'public');
        $dir = trim((string) ($dir ?: config('core-ai.storage.dir', 'ai-images')), '/');
        $prefix = $prefix ?: 'img';
        $ext = self::extFor($mimeType);
        $filename = sprintf('%s-%s.%s', $prefix, (string) Str::ulid(), $ext);
        $path = $dir !== '' ? "{$dir}/{$filename}" : $filename;

        Storage::disk($disk)->put($path, $decoded);

        try {
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            $url = null;
        }

        return [
            'path' => $path,
            'url' => $url,
            'disk' => $disk,
            'filename' => $filename,
        ];
    }

    private static function decodeIfBase64(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Strip any ASCII whitespace (newlines, tabs, etc.) — some encoders
        // wrap the output. Then check both length-mod-4 and character set
        // before handing to base64_decode (with strict=true) for the final
        // authenticity check; this avoids spurious "looks like base64"
        // decoding of short ASCII strings.
        $compact = preg_replace('/\s+/', '', $value);

        if ($compact === '' || strlen($compact) % 4 !== 0) {
            return $value;
        }

        if (! preg_match('#^[A-Za-z0-9+/=]+$#', $compact)) {
            return $value;
        }

        $decoded = base64_decode($compact, true);

        return $decoded === false ? $value : $decoded;
    }

    private static function extFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}
