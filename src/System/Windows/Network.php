<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

use Exception;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\CommandExecutor;
use Sanchescom\WiFi\System\Frequency;
use Sanchescom\WiFi\System\Windows\Profile\Service;

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
        $profileService = $this->getProfileService();

        try {
            $profileService->create($password);

            $command = glue_commands(
                sprintf('netsh wlan add profile filename="%s"', $profileService->getTmpProfileFileName()),
                sprintf('netsh wlan connect interface="%s" ssid="%s" name="%s"', $device, $this->ssid, $this->ssid)
            );

            $this->commandExecutor->execute($command);
        } finally {
            $profileService->delete();
        }
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        $this->commandExecutor->execute(sprintf(' disconnect interface="%s"', $device));
    }

    /**
     * @param array $network
     *
     * @param CommandExecutor $commandExecutor
     *
     * @return Network
     */
    public function createFromArray(array $network, CommandExecutor $commandExecutor): AbstractNetwork
    {
        $instance = new self($commandExecutor);
        $instance->ssid = $network[0];
        $instance->bssid = $network[4];
        $instance->channel = (int)$network[7];
        $instance->security = $network[2];
        $instance->securityFlags = $network[3];
        $instance->quality = (int)$network[5];
        $instance->frequency = $instance->getFrequency();
        $instance->dbm = to_dbm((int)$network[5]);
        $instance->connected = isset($network[10]);

        return $instance;
    }

    protected function getProfileService()
    {
        return new Service($this->ssid, $this->getSecurityType());
    }
}
