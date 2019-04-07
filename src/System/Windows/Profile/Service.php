<?php

namespace Sanchescom\WiFi\System\Windows\Profile;

use Sanchescom\WiFi\System\Windows\Network;

/**
 * Class Service.
 */
class Service
{
    /**
     * @var string
     */
    protected static $tmpFolderName = 'tmp';

    /**
     * @var string
     */
    protected static $templateFolderName = 'templates';

    /**
     * @var string
     */
    protected static $fileNamePostfix = 'PersonalProfileTemplate.xml';

    /**
     * @var Network
     */
    protected $network;

    /**
     * Service constructor.
     *
     * @param Network $network
     */
    public function __construct(Network $network)
    {
        $this->network = $network;
    }

    /**
     * @param string $password
     */
    public function create($password): void
    {
        file_put_contents(
            $this->getTmpProfileFileName(),
            str_replace(
                ['{ssid}', '{hex}', '{key}'],
                [$this->network->ssid, $this->ssidToHex(), $password],
                (file_get_contents($this->getTemplateProfileFileName()) ?: '')
            )
        );
    }

    /**
     * Delete tmp profile file created for chosen network.
     */
    public function delete(): void
    {
        $tmpProfile = $this->getTmpProfileFileName();

        if (file_exists($tmpProfile)) {
            unlink($tmpProfile);
        }
    }

    /**
     * @return string
     */
    public function getTmpProfileFileName(): string
    {
        return __DIR__
            .DIRECTORY_SEPARATOR
            .static::$tmpFolderName
            .DIRECTORY_SEPARATOR
            .$this->network->ssid
            .'.xml';
    }

    /**
     * @return string
     */
    protected function getTemplateProfileFileName(): string
    {
        if ($this->network->isWpa2()) {
            $security = 'WPA2';
        } elseif ($this->network->isWpa()) {
            $security = 'WPA';
        } elseif ($this->network->isWep()) {
            $security = 'WEP';
        } else {
            $security = 'Unknown';
        }

        return __DIR__
            .DIRECTORY_SEPARATOR
            .static::$templateFolderName
            .DIRECTORY_SEPARATOR
            .$security
            .'-'
            .static::$fileNamePostfix;
    }

    /**
     * @return string
     */
    protected function ssidToHex(): string
    {
        $ssid = $this->network->ssid;
        $len = strlen($ssid);
        $hex = '';

        for ($i = 0; $i < $len; $i++) {
            $hex .= substr('0'.dechex(ord($ssid[$i])), -2);
        }

        return strtoupper($hex);
    }
}
