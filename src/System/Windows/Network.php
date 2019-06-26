<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

use Exception;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Command;
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
        $command = glue_commands(
            sprintf('netsh wlan add profile filename="%s"', $this->getProfileService()->create($password)),
            sprintf('netsh wlan connect interface="%s" ssid="%s" name="%s"', $device, $this->ssid, $this->ssid)
        );

        $this->command->execute($command);

        $this->getProfileService()->delete();
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        $this->command->execute(sprintf(' disconnect interface="%s"', $device));
    }

    /**
     * @param array   $network
     * @param Command $command
     *
     * @return Network
     */
    public function createFromArray(array $network, Command $command): AbstractNetwork
    {
        $instance = new self($command);
        $instance->ssid = $network[0];
        $instance->bssid = $network[4];
        $instance->channel = (int) $network[7];
        $instance->security = $network[2];
        $instance->securityFlags = $network[3];
        $instance->quality = (int) $network[5];
        $instance->frequency = $instance->getFrequency();
        $instance->dbm = to_dbm((int) $network[5]);
        $instance->connected = isset($network[10]);

        return $instance;
    }

    /**
     * @return Profile
     */
    protected function getProfileService()
    {
        return new Profile($this->ssid, $this->getSecurityType());
    }
}
