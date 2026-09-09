<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

use InvalidArgumentException;
use RuntimeException;
use Sanchescom\WiFi\System\AbstractNetwork;

/**
 * Renders a netsh WLAN profile for one network into a temporary file.
 */
class Profile
{
    protected string $ssid;

    protected string $securityType;

    protected string $directory;

    protected ?string $fileName = null;

    public function __construct(string $ssid, string $securityType, ?string $directory = null)
    {
        $this->ssid = $ssid;
        $this->securityType = $securityType;
        $this->directory = $directory ?? sys_get_temp_dir();
    }

    /**
     * Write the profile and return its path. The file holds the passphrase in
     * clear text, exactly as netsh requires; delete() it as soon as netsh has
     * read it.
     */
    public function create(string $password): string
    {
        $file = $this->getTmpFileName();

        if (file_put_contents($file, $this->renderTemplate($password)) === false) {
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

    protected function getTmpFileName(): string
    {
        if ($this->fileName === null) {
            $file = tempnam($this->directory, 'php-wifi-');

            if ($file === false) {
                throw new RuntimeException('Unable to create a temporary file in ' . $this->directory);
            }

            $this->fileName = $file;
        }

        return $this->fileName;
    }

    /**
     * @throws InvalidArgumentException when $securityType is not one of the
     *     known security constants — it is otherwise interpolated into a
     *     filesystem path.
     */
    protected function getTemplateFileName(): string
    {
        $allowed = [
            AbstractNetwork::WPA3_SECURITY,
            AbstractNetwork::WPA2_SECURITY,
            AbstractNetwork::WPA_SECURITY,
            AbstractNetwork::WEP_SECURITY,
            AbstractNetwork::UNKNOWN_SECURITY,
        ];

        if (!in_array($this->securityType, $allowed, true)) {
            throw new InvalidArgumentException("Unknown security type [{$this->securityType}]");
        }

        return __DIR__ . '/../../../templates/' . $this->securityType . '.xml';
    }

    protected function renderTemplate(string $password): string
    {
        $content = file_get_contents($this->getTemplateFileName()) ?: '';
        $xml = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return str_replace(
            ['{ssid}', '{hex}', '{key}'],
            [$xml($this->ssid), to_hex($this->ssid), $xml($password)],
            $content
        );
    }
}
