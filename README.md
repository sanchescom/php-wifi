# PHP WiFI

PHP WiFi is cross-platform php library base on integrated in OS utilities

## Getting Started

These instructions will get you a copy of the project up and running on your local machine for development and testing purposes. See deployment for notes on how to deploy the project on a live system.

### Installing

Require this package, with [Composer](https://getcomposer.org/), in the root directory of your project.

``` bash
$ composer require sanchescom/php-wifi
```

## Usage

Create folder `app\Sockets` and put there `ExampleSocket.php`

``` php
<?php

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
```

## Running in console:

### List of found wifi networks
```bash
$ ./vendor/bin/wifi start
```

### List connected wifi networks
```bash
$ ./vendor/bin/wifi list --connected
```

### Connect to wifi network
```bash
$ ./vendor/bin/wifi connect --bssid=4c:49:e3:f5:35:17 --password=12345 --device=en1
```

### Disconnect from wifi network
```bash
$ ./vendor/bin/wifi connect --bssid=4c:49:e3:f5:35:17 --device=en1
```

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct, and the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/sanchescom/laravel-phpsocket.io/tags). 

## Authors

* **Efimov Aleksandr** - *Initial work* - [Sanchescom](https://github.com/sanchescom)

See also the list of [contributors](https://github.com/sanchescom/laravel-phpsocket.io/contributors) who participated in this project.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

## Platform support

* **Linux**
* **MacOS**
* **Windows**
