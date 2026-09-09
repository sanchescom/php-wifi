<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Darwin;

use Sanchescom\WiFi\Contracts\FrequencyInterface;
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
     * @param string $device
     *
     * @throws \Exception
     */
    public function connect(string $password, string $device): void
    {
        $this->getCommand()->execute(sprintf(
            'networksetup -setairportnetwork %s %s %s',
            escapeshellarg($device),
            escapeshellarg($this->ssid),
            escapeshellarg($password)
        ));
    }

    /**
     * @param string $device
     *
     * @throws \Exception
     */
    public function disconnect(string $device): void
    {
        $device = escapeshellarg($device);

        $this->getCommand()->execute(glue_commands(
            sprintf('networksetup -removepreferredwirelessnetwork %s %s', $device, escapeshellarg($this->ssid)),
            sprintf('networksetup -setairportpower %s off', $device),
            sprintf('networksetup -setairportpower %s on', $device)
        ));
    }

    /**
     * @param array<int|string, bool|string> $network
     *
     * @return \Sanchescom\WiFi\System\Darwin\Network
     */
    public function createFromArray(array $network): AbstractNetwork
    {
        $this->ssid = $network[0] ?? '';
        $this->bssid = $network[1] ?? '';
        $this->channel = (int) ($network[3] ?? 0);
        $this->security = $network[6] ?? '';
        $this->securityFlags = $network[5] ?? '';
        $this->setSignalFromDbm((float) ($network[2] ?? 0));
        $band = (string) ($network[4] ?? '');
        $this->frequency = $band === '6'
            ? $this->frequencyFor6GhzChannel($this->channel)
            : $this->getFrequency();
        $this->connected = isset($network[7]);
        $this->ssidRedacted = (bool) ($network['redacted'] ?? false);

        return $this;
    }
}
