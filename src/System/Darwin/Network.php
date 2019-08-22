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
        $this->getCommand()->execute(
            sprintf('networksetup -setairportnetwork %s %s %s', $device, $this->ssid, $password)
        );
    }

    /**
     * @param string $device
     *
     * @throws \Exception
     */
    public function disconnect(string $device): void
    {
        $this->getCommand()->execute(
            glue_commands(
                sprintf('networksetup -removepreferredwirelessnetwork %s %s', $device, $this->ssid),
                sprintf('networksetup -setairportpower %s %s', $device, 'off'),
                sprintf('networksetup -setairportpower %s %s', $device, 'on')
            )
        );
    }

    /**
     * @param array $network
     *
     * @return \Sanchescom\WiFi\System\Darwin\Network
     */
    public function createFromArray(array $network): AbstractNetwork
    {
        $this->ssid = $network[0];
        $this->bssid = $network[1];
        $this->channel = (int) $network[3];
        $this->security = $network[6];
        $this->securityFlags = $network[5];
        $this->quality = $network[2];
        $this->frequency = $this->getFrequency();
        $this->dbm = to_dbm((int) $network[2]);
        $this->connected = isset($network[7]);

        return $this;
    }
}
