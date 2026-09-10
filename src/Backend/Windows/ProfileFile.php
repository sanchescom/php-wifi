<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend\Windows;

use RuntimeException;
use Sanchescom\WiFi\Value\Security;

/**
 * Renders a netsh WLAN profile for one network into a temporary file. The
 * file is the only place the password ever exists in clear text; delete()
 * it as soon as netsh has read it.
 *
 * Write and temp-file-creation failures throw the plain SPL
 * `\RuntimeException`, not a `WiFiException` — this mirrors the 2.x
 * `Profile` class behaviour and keeps our own exception family reserved
 * for command-execution failures.
 */
final class ProfileFile
{
    private ?string $fileName = null;

    public function __construct(
        private readonly string $ssid,
        private readonly Security $security,
        private readonly ?string $directory = null,
    ) {
    }

    /**
     * Write the profile and return its path.
     *
     * @throws RuntimeException when the temporary file cannot be created or written to
     */
    public function create(string $password): string
    {
        $file = $this->tempFileName();

        if (file_put_contents($file, $this->render($password)) === false) {
            throw new RuntimeException('Unable to write the WLAN profile to ' . $file);
        }

        return $file;
    }

    public function delete(): bool
    {
        if ($this->fileName !== null && file_exists($this->fileName)) {
            return unlink($this->fileName);
        }

        return false;
    }

    /** @throws RuntimeException when a temporary file cannot be created */
    private function tempFileName(): string
    {
        if ($this->fileName === null) {
            $directory = $this->directory ?? sys_get_temp_dir();
            $file = tempnam($directory, 'php-wifi-');

            if ($file === false) {
                throw new RuntimeException('Unable to create a temporary file in ' . $directory);
            }

            $this->fileName = $file;
        }

        return $this->fileName;
    }

    /** @throws RuntimeException when the template file cannot be read */
    private function render(string $password): string
    {
        $template = __DIR__ . '/../../../templates/' . $this->security->windowsProfileTemplate() . '.xml';
        $content = file_get_contents($template);

        if ($content === false) {
            throw new RuntimeException('Unable to read the WLAN profile template ' . $template);
        }

        $xml = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        return str_replace(
            ['{ssid}', '{hex}', '{key}'],
            [$xml($this->ssid), bin2hex($this->ssid), $xml($password)],
            $content,
        );
    }
}
