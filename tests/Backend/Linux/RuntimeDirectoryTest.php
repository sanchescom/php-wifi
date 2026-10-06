<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Backend\Linux;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sanchescom\WiFi\Backend\Linux\RuntimeDirectory;

final class RuntimeDirectoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/php-wifi-runtime-test-' . bin2hex(random_bytes(8));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        putenv('WIFI_RUNTIME_DIR');

        foreach (glob($this->base . '/*') ?: [] as $entry) {
            is_link($entry) ? unlink($entry) : rmdir($entry);
        }

        rmdir($this->base);
        parent::tearDown();
    }

    #[Test]
    public function ensure_creates_a_directory_only_its_owner_can_enter(): void
    {
        $path = (new RuntimeDirectory($this->base . '/run'))->ensure();

        $this->assertSame($this->base . '/run', $path);
        $this->assertSame(0700, fileperms($path) & 0777);
    }

    #[Test]
    public function the_environment_overrides_the_default_path(): void
    {
        putenv('WIFI_RUNTIME_DIR=' . $this->base . '/custom/');

        $this->assertSame($this->base . '/custom', (new RuntimeDirectory())->path());
    }

    /** The attack this class exists for: a path someone else pointed at a directory of their choosing. */
    #[Test]
    public function ensure_refuses_a_symlink(): void
    {
        mkdir($this->base . '/elsewhere', 0700);
        symlink($this->base . '/elsewhere', $this->base . '/run');

        $this->expectException(RuntimeException::class);

        (new RuntimeDirectory($this->base . '/run'))->ensure();
    }

    #[Test]
    public function ensure_refuses_a_directory_others_can_write_to(): void
    {
        mkdir($this->base . '/run');
        chmod($this->base . '/run', 0777);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not safe');

        (new RuntimeDirectory($this->base . '/run'))->ensure();
    }
}
