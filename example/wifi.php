<?php

require_once(__DIR__ . '/../vendor/autoload.php');

use Sanchescom\WiFi\WiFi;

class Example
{
    public $device;

    /**
     * @throws Exception
     */
    public function getAllNetworks()
    {
        $allNetworks = WiFi::scan()->getAll();

        if (count($allNetworks) > 0) {
            foreach ($allNetworks as $network) {
                echo $network . "\n";
            }
        }
    }

    /**
     * @param $ssid
     * @param $password
     * @throws Exception
     */
    public function connect($ssid, $password)
    {
        $networks = WiFi::scan()
            ->getBySsid($ssid);

        if (count($networks) > 0) {
            $networks[0]->connect($password, $this->device);
        } else {
            echo "Network $ssid wasn't found!\r\n";
        }
    }

    /**
     * @throws Exception
     */
    public function disconnect()
    {
        $connectedNetworks = WiFi::scan()->getConnected();

        foreach ($connectedNetworks as $network) {
            $network->disconnect($this->device);
        }
    }
}

$example = new Example();
try {
    $example->device = 'en1';
    $example->getAllNetworks();
    $example->connect('Redmi', '12345');
    $example->disconnect();
} catch (Exception $e) {
    //
}
