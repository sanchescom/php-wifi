<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

use Exception;
use pastuhov\Command\Command;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\UtilityInterface;

/**
 * Class Network.
 */
class Network extends AbstractNetwork implements UtilityInterface
{
    use UtilityTrait;

    /**
     * @var string
     */
    const POSITIVE_CONNECTION_FLAG = 'yes';

    /**
     * @param string $password
     * @param string $device
     *
     * @throws Exception
     */
    public function connect(string $password, string $device): void
    {
        Command::exec(
            sprintf(
                $this->getUtility().' -w 10 device wifi connect "%s" password "%s" ifname "%s"',
                $this->ssid,
                $password,
                $device
            )
        );
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        Command::exec(
            implode(' && ', [
                sprintf($this->getUtility().' device disconnect %s', $device),
            ])
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
        $instance->ssid = $network[1];
        $instance->bssid = $network[2];
        $instance->channel = (int) $network[4];
        $instance->security = $network[7];
        $instance->securityFlags = $network[8].' '.$network[9];
        $instance->dbm = $network[6];
        $instance->quality = $instance->dBmToQuality();
        $instance->frequency = (int) $network[5];
        $instance->connected = ($network[0] == self::POSITIVE_CONNECTION_FLAG);

        return $instance;
    }
}
