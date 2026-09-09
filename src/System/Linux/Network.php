<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

use Sanchescom\WiFi\Exceptions\DeviceNotFoundException;
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
     * @param string|null $device
     *
     * @throws \Exception
     */
    public function connect(string $password, ?string $device = null): void
    {
        $device = $this->resolveDevice($device);

        $this->getCommand()->execute(sprintf(
            'LANG=C nmcli -w 10 device wifi connect %s password %s ifname %s',
            escapeshellarg($this->ssid),
            escapeshellarg($password),
            escapeshellarg($device)
        ));
    }

    /**
     * @param string|null $device
     *
     * @throws \Exception
     */
    public function disconnect(?string $device = null): void
    {
        $device = $this->resolveDevice($device);

        $this->getCommand()->execute(sprintf('LANG=C nmcli device disconnect %s', escapeshellarg($device)));
    }

    protected function detectDevice(): string
    {
        $output = (string) $this->getCommand()->execute('LANG=C nmcli -t -f DEVICE,TYPE device');

        foreach (explode("\n", trim($output)) as $line) {
            [$device, $type] = array_pad(explode(':', $line, 3), 2, '');

            if ($type === 'wifi' && $device !== '') {
                return $device;
            }
        }

        throw new DeviceNotFoundException('nmcli lists no device of type wifi.');
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
