<?php

namespace Ubxty\CoreAi\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Ubxty\CoreAi\Support\ImagePersistence;

class ImagePersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('core-ai.storage.disk', 'public');
        config()->set('core-ai.storage.dir', 'ai-images');
    }

    public function test_persists_raw_png_bytes(): void
    {
        Storage::fake('public');

        $bytes = "\x89PNG\r\n\x1a\nfakepng";
        $stored = ImagePersistence::persist($bytes, 'image/png');

        $this->assertSame('public', $stored['disk']);
        $this->assertStringStartsWith('ai-images/img-', $stored['path']);
        $this->assertStringEndsWith('.png', $stored['path']);
        Storage::disk('public')->assertExists($stored['path']);
        $this->assertSame($bytes, Storage::disk('public')->get($stored['path']));
    }

    public function test_persists_jpeg_with_jpg_extension(): void
    {
        Storage::fake('public');

        $bytes = "\xFF\xD8\xFFfakejpg";
        $stored = ImagePersistence::persist($bytes, 'image/jpeg');

        $this->assertStringEndsWith('.jpg', $stored['path']);
        Storage::disk('public')->assertExists($stored['path']);
    }

    public function test_decodes_base64_input(): void
    {
        Storage::fake('public');

        $raw = 'binary-image-bytes';
        $b64 = base64_encode($raw);

        $stored = ImagePersistence::persist($b64, 'image/png');

        $this->assertSame($raw, Storage::disk('public')->get($stored['path']));
    }

    public function test_disk_override(): void
    {
        Storage::fake('public');
        Storage::fake('s3');

        $stored = ImagePersistence::persist('x', 'image/png', disk: 's3');

        $this->assertSame('s3', $stored['disk']);
        $this->assertStringStartsWith('ai-images/img-', $stored['path']);
        Storage::disk('s3')->assertExists($stored['path']);
    }

    public function test_dir_override(): void
    {
        Storage::fake('public');

        $stored = ImagePersistence::persist('x', 'image/png', dir: 'gallery/2026');

        $this->assertStringStartsWith('gallery/2026/img-', $stored['path']);
    }

    public function test_filename_prefix(): void
    {
        Storage::fake('public');

        $stored = ImagePersistence::persist('x', 'image/png', prefix: 'hero');

        $this->assertStringStartsWith('ai-images/hero-', $stored['path']);
    }

    public function test_rejects_unsupported_mime(): void
    {
        Storage::fake('public');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image MIME type not supported');

        ImagePersistence::persist('x', 'image/bmp');
    }

    public function test_url_present_when_storage_provides_one(): void
    {
        Storage::fake('public');

        $stored = ImagePersistence::persist('x', 'image/png');

        // Storage::fake returns a path-based URL like /storage/...
        $this->assertNotNull($stored['url']);
        $this->assertStringContainsString($stored['filename'], (string) $stored['url']);
    }

    public function test_ulid_in_filename_is_lexicographically_sorted(): void
    {
        Storage::fake('public');

        $first = ImagePersistence::persist('a', 'image/png');
        // tiny sleep to guarantee distinct ULIDs
        usleep(2000);
        $second = ImagePersistence::persist('b', 'image/png');

        $this->assertLessThan(
            0,
            strcmp(basename($first['path']), basename($second['path'])),
            'ULID-based filenames should sort by creation time',
        );
        // ensure ULID shape (26 chars, Crockford base32)
        $firstId = Str::after(basename($first['path']), 'img-');
        $firstId = Str::before($firstId, '.png');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $firstId);
    }
}
