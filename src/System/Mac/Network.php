<?php

declare(strict_types=1);

namespace Sanchescom\Wifi\System\Mac;

use Exception;
use pastuhov\Command\Command;
use Sanchescom\Wifi\System\AbstractNetwork;

class Network extends AbstractNetwork
{
    use UtilityTrait;

    /**
     * @param string $password
     * @param string $device
     * @throws Exception
     */
    public function connect(string $password, string $device): void
    {
        Command::exec(
            sprintf($this->getUtility() . ' -setairportnetwork %s %s %s', $device, $this->ssid, $password)
        );
    }

    /**
     * @param string $device
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        Command::exec(
            implode(' && ', [
                sprintf($this->getUtility() . ' -removepreferredwirelessnetwork %s %s', $device, $this->ssid),
                sprintf($this->getUtility() . ' -setairportpower %s %s', $device, 'off'),
                sprintf($this->getUtility() . ' -setairportpower %s %s', $device, 'on')
            ])
        );
    }

    /**
     * @param array $network
     * @return Network
     */
    public static function createFromArray(array $network): AbstractNetwork
    {
        $instance = new self();
        $instance->ssid = $network[0];
        $instance->bssid = $network[1];
        $instance->channel = (int)$network[3];
        $instance->security = $network[6];
        $instance->securityFlags = $network[5];
        $instance->quality = $network[2];
        $instance->frequency = $instance->getFrequency();
        $instance->dbm = $instance->qualityToDBm();
        $instance->connected = isset($network[7]);

        return $instance;
    }
}
