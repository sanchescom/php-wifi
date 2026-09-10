<?php

/**
 * Headless Wi-Fi provisioning page: run behind a temporary hotspot (see
 * hotspot.sh) so a phone joining that hotspot can pick a network and a
 * password without SSH. Plain PHP + HTML, no JS, no session, no framework.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../../vendor/autoload.php';

if (!is_file($autoload)) {
    $autoload = __DIR__ . '/../../../autoload.php';
}

if (!is_file($autoload)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies are not installed.\nRun: composer install --no-dev\n";
    exit(1);
}

require $autoload;

use Sanchescom\WiFi\Exception\WiFiException;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\WiFi;

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$bandLabel = static fn (?Band $band): string => match ($band) {
    Band::GHz2_4 => '2.4 GHz',
    Band::GHz5 => '5 GHz',
    Band::GHz6 => '6 GHz',
    null => '?',
};

$networks = [];
$scanError = null;
$connectError = null;
$connected = null;

try {
    $wifi = WiFi::create();
} catch (Throwable $exception) {
    $scanError = $exception->getMessage();
    $wifi = null;
}

if ($wifi !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        foreach ($wifi->scan()->uniqueBySsid()->sortBySignal() as $network) {
            if ($network->ssidHidden) {
                continue;
            }

            $quality = $network->signal !== null ? (string) (int) round($network->signal->quality) . '%' : '?';
            $networks[] = [
                'ssid' => $network->ssid,
                'label' => sprintf('%s · %s · %s', $network->ssid, ($bandLabel)($network->band), $quality),
            ];
        }
    } catch (WiFiException $exception) {
        $scanError = $exception->getMessage();
    }
}

if ($wifi !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ssid = $_POST['ssid'] ?? null;
    $password = $_POST['password'] ?? '';

    if (!is_string($ssid) || $ssid === '' || !is_string($password)) {
        $connectError = 'Choose a network from the list.';
    } else {
        try {
            $credentials = $password === '' ? Credentials::none() : Credentials::password($password);
            $wifi->connect($ssid, $credentials);
            $connected = ['device' => $wifi->device()->name, 'ssid' => $ssid];
        } catch (WiFiException $exception) {
            $connectError = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wi-Fi setup</title>
<style>
body{font:16px/1.4 sans-serif;margin:0;padding:1rem;background:#111;color:#eee}
h1{font-size:1.2rem}
label{display:block;padding:.6rem;border:1px solid #333;border-radius:.4rem;margin-bottom:.5rem}
input[type=password]{width:100%;padding:.6rem;margin:.5rem 0;box-sizing:border-box}
button{width:100%;padding:.7rem;font-size:1rem;background:#2a7;color:#fff;border:0;border-radius:.4rem}
.msg{padding:.6rem;border-radius:.4rem;margin-bottom:1rem}
.ok{background:#264}
.err{background:#422}
</style>
</head>
<body>
<h1>Wi-Fi setup</h1>
<?php if ($connected !== null) : ?>
<p class="msg ok">Connected device <strong><?= ($h)($connected['device']) ?></strong> to
<strong><?= ($h)($connected['ssid']) ?></strong>.</p>
<?php endif; ?>
<?php if ($connectError !== null) : ?>
<p class="msg err"><?= ($h)($connectError) ?></p>
<?php endif; ?>
<?php if ($scanError !== null) : ?>
<p class="msg err">Could not scan for networks: <?= ($h)($scanError) ?></p>
<?php elseif ($networks === []) : ?>
<p>No networks found. Reload the page to scan again.</p>
<?php else : ?>
<form method="post">
    <?php foreach ($networks as $network) : ?>
<label>
<input type="radio" name="ssid" value="<?= ($h)($network['ssid']) ?>" required>
        <?= ($h)($network['label']) ?>
</label>
    <?php endforeach; ?>
<input type="password" name="password" placeholder="Password (leave empty if open)" autocomplete="off">
<button type="submit">Connect</button>
</form>
<?php endif; ?>
</body>
</html>
