<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

use Sanchescom\WiFi\System\AbstractNetwork;

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
     * @throws \Exception
     */
    public function connect(string $password, string $device): void
    {
        $format = 'LANG=C nmcli -w 10 device wifi connect "%s" password "%s" ifname "%s"';

        $this->command->execute(sprintf($format, $this->ssid, $password, $device));
    }

    /**
     * @param string $device
     *
     * @throws \Exception
     */
    public function disconnect(string $device): void
    {
        $this->command->execute(sprintf('LANG=C nmcli device disconnect %s', $device));
    }

    /**
     * @param array $network
     *
     * @return \Sanchescom\WiFi\System\Linux\Network
     */
    public function createFromArray(array $network): AbstractNetwork
    {
        $this->ssid = $network[1];
        $this->bssid = $network[2];
        $this->channel = (int) $network[4];
        $this->security = $network[7];
        $this->securityFlags = $network[8].' '.$network[9];
        $this->dbm = $network[6];
        $this->quality = to_quality($network[6]);
        $this->frequency = (int) $network[5];
        $this->connected = ($network[0] == self::POSITIVE_CONNECTION_FLAG);

        return $this;
    }
}
