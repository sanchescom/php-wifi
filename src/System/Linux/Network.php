<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

use Exception;
use Sanchescom\WiFi\System\AbstractNetwork;
use Sanchescom\WiFi\System\Command;

/**
 * Class Network.
 */
class Network extends AbstractNetwork
{
    /** @var string */
    protected const POSITIVE_CONNECTION_FLAG = 'yes';

    /**
     * @param string $password
     * @param string $device
     *
     * @throws Exception
     */
    public function connect(string $password, string $device): void
    {
        $format = 'LANG=C nmcli -w 10 device wifi connect "%s" password "%s" ifname "%s"';

        $this->command->execute(sprintf($format, $this->ssid, $password, $device));
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        $this->command->execute(sprintf('LANG=C nmcli device disconnect %s', $device));
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
        $instance->ssid = $network[1];
        $instance->bssid = $network[2];
        $instance->channel = (int) $network[4];
        $instance->security = $network[7];
        $instance->securityFlags = $network[8].' '.$network[9];
        $instance->dbm = $network[6];
        $instance->quality = to_quality($network[6]);
        $instance->frequency = (int) $network[5];
        $instance->connected = ($network[0] == self::POSITIVE_CONNECTION_FLAG);

        return $instance;
    }
}
