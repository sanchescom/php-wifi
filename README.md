[![Build Status](https://travis-ci.org/sanchescom/php-wifi.svg?branch=master)](https://travis-ci.org/sanchescom/php-wifi)
[![codecov](https://codecov.io/gh/sanchescom/php-wifi/branch/master/graph/badge.svg)](https://codecov.io/gh/sanchescom/php-wifi)
[![Maintainability](https://api.codeclimate.com/v1/badges/852384730259754d4008/maintainability)](https://codeclimate.com/github/sanchescom/php-wifi/maintainability)
[![StyleCI](https://github.styleci.io/repos/175257648/shield?branch=master)](https://github.styleci.io/repos/168349832)
[![Quality Score](https://img.shields.io/scrutinizer/g/sanchescom/laravel-phpsocket.io.svg?style=flat-square)](https://scrutinizer-ci.com/g/sanchescom/php-wifi)

# PHP WiFi

PHP WiFi is cross-platform php library based on integrated in OS utilities

## Getting Started

These instructions will get you a copy of the project up and running on your local machine for development and testing purposes. See deployment for notes on how to deploy the project on a live system.

### Installing

Require this package, with [Composer](https://getcomposer.org/), in the root directory of your project.

``` bash
$ composer require sanchescom/php-wifi
```

## Usage

``` php
<?php

include __DIR__ . '/vendor/autoload.php';

use Sanchescom\WiFi\WiFi;

class Example
{
    public $device;

    /**
     * @throws Exception
     */
    public function getAllNetworks()
    {
        $networks = WiFi::scan()->getAll();

        foreach ($networks as $network) {
            echo $network . "\n";
        }
    }

    /**
     * @param $ssid
     * @param $password
     */
    public function connect($ssid, $password)
    {
        try {
            WiFi::scan()->getBySsid($ssid)->connect($password, $this->device);
        } catch (Exception $exception) {
            echo $exception->getMessage();
        }
    }

    /**
     * @throws Exception
     */
    public function disconnect()
    {
        $networks = WiFi::scan()->getConnected();

        foreach ($networks as $network) {
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
$ ./vendor/bin/wifi list
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
$ ./vendor/bin/wifi disconnect --bssid=4c:49:e3:f5:35:17 --device=en1
```

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct, and the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/sanchescom/php-wifi/tags). 

## Authors

* **Efimov Aleksandr** - *Initial work* - [Sanchescom](https://github.com/sanchescom)

See also the list of [contributors](https://github.com/sanchescom/php-wifi/contributors) who participated in this project.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

## Platform support

* **Linux**
* **MacOS**
* **Windows**