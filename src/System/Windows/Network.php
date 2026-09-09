<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

use Sanchescom\WiFi\Contracts\FrequencyInterface;
use Sanchescom\WiFi\Exceptions\DeviceNotFoundException;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Frequency;

/**
 * Class Network.
 */
class Network extends AbstractNetwork implements FrequencyInterface
{
    use Frequency;

    /**
     * @param string $password
     * @param string|null $device
     *
     * @throws \Exception
     */
    public function connect(string $password, ?string $device = null): void
    {
        $device = $this->resolveDevice($device);
        $profile = $this->getProfileService();

        try {
            $this->getCommand()->execute(glue_commands(
                sprintf('netsh wlan add profile filename=%s', $this->quote($profile->create($password))),
                sprintf(
                    'netsh wlan connect interface=%s ssid=%s name=%s',
                    $this->quote($device),
                    $this->quote($this->ssid),
                    $this->quote($this->ssid)
                )
            ));
        } finally {
            $profile->delete();
        }
    }

    /**
     * @param string|null $device
     *
     * @throws \Exception
     */
    public function disconnect(?string $device = null): void
    {
        $device = $this->resolveDevice($device);

        $this->getCommand()->execute(sprintf('netsh wlan disconnect interface=%s', $this->quote($device)));
    }

    protected function detectDevice(): string
    {
        $output = (string) $this->getCommand()->execute('netsh wlan show interfaces');

        if (preg_match('/^\s*Name\s*:\s*(.+?)\s*$/m', $output, $m)) {
            return $m[1];
        }

        throw new DeviceNotFoundException('netsh lists no WLAN interface.');
    }

    /**
     * Quote a value for cmd.exe. cmd has no single quotes and expands %VAR%
     * and !VAR! even inside double quotes, so the three characters that can
     * break out of the argument are replaced with a space — the same thing
     * PHP's escapeshellarg() does on Windows, done here so the result does
     * not depend on the host PHP runs on.
     */
    protected function quote(string $value): string
    {
        return '"' . str_replace(['"', '%', '!'], ' ', $value) . '"';
    }

    /**
     * @param array<int, string> $network
     *
     * @return \Sanchescom\WiFi\System\Windows\Network
     */
    public function createFromArray(array $network): AbstractNetwork
    {
        $this->ssid = $network[0];
        $this->bssid = $network[4];
        $this->channel = (int) $network[7];
        $this->security = $network[2];
        $this->securityFlags = $network[3];
        $this->setSignalFromQuality((float) $network[5]);
        $this->frequency = $this->getFrequency();
        $this->connected = isset($network[10]);

        return $this;
    }

    protected function getProfileService(): Profile
    {
        return new Profile($this->ssid, $this->getSecurityType());
    }
}
