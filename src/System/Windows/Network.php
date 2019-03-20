<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

use Exception;
use pastuhov\Command\Command;
use Sanchescom\WiFi\System\AbstractNetwork;

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
        $this->createProfile($password);

        Command::exec(
            implode(' && ', [
                sprintf(
                    ($this->getUtility() . ' add profile filename="%s"'),
                    $this->getTmpFileName()
                ),
                sprintf(
                    ($this->getUtility() . ' connect interface="%s" ssid="%s" name="%s"'),
                    $device,
                    $this->ssid,
                    $this->ssid
                ),
            ])
        );

        @unlink($this->getTmpFileName());
    }

    /**
     * @param string $device
     *
     * @throws Exception
     */
    public function disconnect(string $device): void
    {
        Command::exec(
            sprintf($this->getUtility() . ' disconnect interface="%s"', $device)
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
        $instance->quality = (int)$network[5];
        $instance->frequency = $instance->getFrequency();
        $instance->dbm = $instance->qualityToDBm();
        $instance->connected = isset($network[10]);

        return $instance;
    }

    /**
     * @param string $password
     */
    protected function createProfile($password): void
    {
        $fileNamePrefix = __DIR__ . './ProfileTemplates/';
        $fileNamePostfix = 'PersonalProfileTemplate.xml';

        if (strpos($this->security, 'WPA2') !== false) {
            $fileName = $fileNamePrefix . "WPA2-" . $fileNamePostfix;
        } elseif (strpos($this->security, 'WEP') !== false) {
            $fileName = $fileNamePrefix . "WPA-" . $fileNamePostfix;
        } elseif (strpos($this->security, 'WEP') !== false) {
            $fileName = $fileNamePrefix . "WEP-" . $fileNamePostfix;
        } else {
            $fileName = $fileNamePrefix . "Unknown-" . $fileNamePostfix;
        }

        unlink($this->getTmpFileName());

        file_put_contents(
            $this->getTmpFileName(),
            str_replace(
                ['{ssid}', '{hex}', '{key}'],
                [$this->ssid, $this->ssidToHex(), $password],
                (file_get_contents($fileName) ?: '')
            )
        );
    }

    /**
     * @return string
     */
    protected function getTmpFileName(): string
    {
        return __DIR__. "\\..\\..\\..\\tmp\\" . $this->ssid . '.xml';
    }

    /**
     * @return string
     */
    protected function ssidToHex(): string
    {
        $hex = '';

        for ($i = 0; $i < strlen($this->ssid); $i++) {
            $ord    = ord($this->ssid[$i]);
            $dechex = dechex($ord);
            $hex   .= substr('0' . $dechex, -2);
        }

        return strtoupper($hex);
    }
}
