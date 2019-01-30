PHP Wifi
==========

PHP Wifi is cross-platform php library base on integrated in OS utilities

Example
-------

```php
<?php
require_once(__DIR__ . '/../vendor/autoload.php');

use Sanchescom\Wifi\Wifi;

class Example
{
    public $device;

    /**
     * @throws Exception
     */
    public function getAllNetworks()
    {
        $allNetworks = Wifi::scan()->getAll();

        if (count($allNetworks) > 0) {
            echo "SSID (BSSID):\r\n";

            foreach ($allNetworks as $network) {
                echo $network->ssid . "(" . $network->bssid . ")\r\n ";
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
        $networks = Wifi::scan()
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
        $connectedNetworks = Wifi::scan()->getConnected();

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
```

Use as CLI
-------
```bash
    php wifi list //List of found wifi networks
    
    php wifi list --connected  //List connected wifi networks
    
    php wifi connect --bssid=4c:49:e3:f5:35:17 --password=12345 --device=en1  //Connect to wifi network
    
    php wifi connect --bssid=4c:49:e3:f5:35:17 --device=en1  //Disconnect from wifi network
```

### Platform support

* **Linux**
* **MacOS**
* **Windows**
