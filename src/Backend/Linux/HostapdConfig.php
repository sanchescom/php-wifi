<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend\Linux;

use RuntimeException;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\HotspotConfig;

/**
 * Renders a `hostapd` config for one hotspot into a temporary file. Mirrors
 * {@see \Sanchescom\WiFi\Backend\Windows\ProfileFile}: the file is the only
 * place the passphrase ever exists in clear text; delete() it as soon as
 * `hostapd` has read it (it daemonises, so the file can go immediately after
 * a successful start).
 *
 * `hostapd`'s config format is line-based `key=value`; a newline or carriage
 * return inside the SSID or passphrase would inject an arbitrary directive
 * into the file, so both are rejected up front.
 */
final class HostapdConfig
{
    private ?string $fileName = null;

    private readonly string $hwMode;

    private readonly int $channel;

    /**
     * @throws InvalidArgument when the SSID or passphrase contains a newline or carriage return
     * @throws UnsupportedOperation when $config->band is Band::GHz6
     */
    public function __construct(
        private readonly string $interface,
        private readonly HotspotConfig $config,
        private readonly ?string $directory = null,
    ) {
        self::guardAgainstInjection($config->ssid);
        self::guardAgainstInjection($config->password);

        [$this->hwMode, $this->channel] = match ($config->band ?? Band::GHz2_4) {
            Band::GHz2_4 => ['g', 6],
            Band::GHz5 => ['a', 36],
            Band::GHz6 => throw new UnsupportedOperation('HostapdConfig does not support a 6 GHz hotspot.'),
        };
    }

    /**
     * Write the config and return its path.
     *
     * @throws RuntimeException when the temporary file cannot be created or written to
     */
    public function create(): string
    {
        $file = $this->tempFileName();

        if (file_put_contents($file, $this->render()) === false) {
            throw new RuntimeException('Unable to write the hostapd config to ' . $file);
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
            $file = tempnam($directory, 'php-wifi-hostapd-');

            if ($file === false) {
                throw new RuntimeException('Unable to create a temporary file in ' . $directory);
            }

            $this->fileName = $file;
        }

        return $this->fileName;
    }

    private function render(): string
    {
        $lines = [
            'interface=' . $this->interface,
            'driver=nl80211',
            'ssid=' . $this->config->ssid,
            'hw_mode=' . $this->hwMode,
            'channel=' . $this->channel,
            'wpa=2',
            'wpa_passphrase=' . $this->config->password,
            'wpa_key_mgmt=WPA-PSK',
            'rsn_pairwise=CCMP',
        ];

        return implode("\n", $lines) . "\n";
    }

    /** @throws InvalidArgument when $value contains a newline or carriage return */
    private static function guardAgainstInjection(string $value): void
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgument(
                'A hostapd config value must not contain a newline or carriage return; '
                . 'it would inject an arbitrary directive into the config file.',
            );
        }
    }
}
