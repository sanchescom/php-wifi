<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Mac;

use Exception;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\CommandExecutor;
use Sanchescom\WiFi\System\Frequency;

/**
 * Class Network.
 */
class Network extends AbstractNetwork
{
    use Frequency;

    /**
     * @param string $password
     * @param string $device
     *
     * @throws Exception
     */
    public function connect(string $password, string $device): void
    {
        $this->commandExecutor->execute(
            sprintf('networksetup -setairportnetwork %s %s %s', $device, $this->ssid, $password)
        );
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        $this->commandExecutor->execute(
            glue_commands(
                sprintf('networksetup -removepreferredwirelessnetwork %s %s', $device, $this->ssid),
                sprintf('networksetup -setairportpower %s %s', $device, 'off'),
                sprintf('networksetup -setairportpower %s %s', $device, 'on')
            )
        );
    }

    /**
     * @param array $network
     * @param CommandExecutor $commandExecutor
     *
     * @return Network
     */
    public function createFromArray(array $network, CommandExecutor $commandExecutor): AbstractNetwork
    {
        $instance = new self($commandExecutor);
        $instance->ssid = $network[0];
        $instance->bssid = $network[1];
        $instance->channel = (int) $network[3];
        $instance->security = $network[6];
        $instance->securityFlags = $network[5];
        $instance->quality = $network[2];
        $instance->frequency = $instance->getFrequency();
        $instance->dbm = to_dbm($network[2]);
        $instance->connected = isset($network[7]);

        return $instance;
    }
}
