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
        $this->getCommand()->execute(sprintf(
            'LANG=C nmcli -w 10 device wifi connect %s password %s ifname %s',
            escapeshellarg($this->ssid),
            escapeshellarg($password),
            escapeshellarg($device)
        ));
    }

    /**
     * @param string $device
     *
     * @throws \Exception
     */
    public function disconnect(string $device): void
    {
        $this->getCommand()->execute(sprintf('LANG=C nmcli device disconnect %s', escapeshellarg($device)));
    }

    /**
     * @param array<int, string> $network
     *
     * @return \Sanchescom\WiFi\System\Linux\Network
     */
    public function createFromArray(array $network): AbstractNetwork
    {
        $this->ssid = $network[1];
        $this->bssid = $network[2];
        $this->channel = (int) $network[4];
        $this->security = $network[7];
        $this->securityFlags = $network[8] . ' ' . $network[9];
        $this->setSignalFromQuality((float) $network[6]);
        $this->frequency = (int) $network[5];
        $this->connected = ($network[0] == self::POSITIVE_CONNECTION_FLAG);

        return $this;
    }
}
