<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

use Exception;
use pastuhov\Command\Command;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Windows\Profile\Service;

/**
 * Class Network.
 */
class Network extends AbstractNetwork
{
    use UtilityTrait;

    /**
     * @param string $password
     * @param string $device
     *
     * @throws Exception
     */
    public function connect(string $password, string $device): void
    {
        $this->getProfileService()->create($password);

        Command::exec(
            implode(' && ', [
                sprintf(
                    ($this->getUtility().' add profile filename="%s"'),
                    $this->getProfileService()->getTmpProfileFileName()
                ),
                sprintf(
                    ($this->getUtility().' connect interface="%s" ssid="%s" name="%s"'),
                    $device,
                    $this->ssid,
                    $this->ssid
                ),
            ])
        );

        $this->getProfileService()->delete();
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        Command::exec(
            sprintf($this->getUtility().' disconnect interface="%s"', $device)
        );
    }

    /**
     * @param array $network
     *
     * @return Network
     */
    public static function createFromArray(array $network): AbstractNetwork
    {
        $instance = new self();
        $instance->ssid = $network[0];
        $instance->bssid = $network[4];
        $instance->channel = $network[7];
        $instance->security = $network[2];
        $instance->securityFlags = $network[3];
        $instance->quality = (int) $network[5];
        $instance->frequency = $instance->getFrequency();
        $instance->dbm = $instance->qualityToDBm();
        $instance->connected = isset($network[10]);

        return $instance;
    }

    protected function getProfileService()
    {
        return new Service($this);
    }
}
