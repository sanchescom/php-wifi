<?php

declare(strict_types=1);

/*
 * The router script `wifi provision` hands to PHP's built-in web server
 * (`php -S <address> bin/wifi-portal.php`): every request to the setup
 * hotspot ends up here. Not meant to be run by hand.
 */

use Sanchescom\WiFi\Cli\WiFiCli;
use Sanchescom\WiFi\Provision\Portal;
use Sanchescom\WiFi\Provision\SetupPage;
use Sanchescom\WiFi\Provision\State;
use Sanchescom\WiFi\Value\HotspotConfig;

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../autoload.php')) {
    require __DIR__ . '/../../../autoload.php';
} else {
    require __DIR__ . '/../vendor/autoload.php';
}

$url = getenv('WIFI_PORTAL_URL');
$name = getenv('WIFI_PORTAL_NAME');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$host = $_SERVER['HTTP_HOST'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$page = new SetupPage(
    new Portal(WiFiCli::buildWifi()[0], new State()),
    is_string($url) && $url !== '' ? $url : 'http://' . HotspotConfig::ADDRESS . '/',
    is_string($name) && $name !== '' ? $name : null,
);

$page->handle(
    is_string($method) ? $method : 'GET',
    is_string($host) ? $host : '',
    (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH),
    $_POST,
)->send();
