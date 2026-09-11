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

use Sanchescom\WiFi\Backend\SupportsHotspot;
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

/**
 * Reads and decodes the pre-hotspot scan cache. Returns null when the file
 * is missing, unreadable or does not decode to a JSON array — a signal to
 * fall back to a live scan. Returns an array (possibly empty) when the
 * cache is valid: an empty cache means the pre-scan legitimately found no
 * networks, and must not trigger a live scan while the hotspot is up.
 */
$readCache = static function (string $path) use ($bandLabel): ?array {
    $cached = is_readable($path) ? file_get_contents($path) : false;

    if ($cached === false || trim($cached) === '') {
        return null;
    }

    $decoded = json_decode($cached, true);

    if (!is_array($decoded)) {
        return null;
    }

    $networks = [];

    foreach ($decoded as $row) {
        if (!is_array($row) || ($row['hidden'] ?? false) === true) {
            continue;
        }

        $ssid = (string) ($row['ssid'] ?? '');
        $band = is_string($row['band'] ?? null) ? Band::tryFrom($row['band']) : null;
        $quality = is_int($row['quality'] ?? null) ? $row['quality'] . '%' : '?';

        $networks[] = [
            'ssid' => $ssid,
            'label' => sprintf('%s · %s · %s', $ssid, ($bandLabel)($band), $quality),
        ];
    }

    return $networks;
};

$networks = [];
$scanError = null;
$connectError = null;
$connected = null;
$fromCache = false;
$genericError = 'Something went wrong on the device; check its logs.';

try {
    $wifi = WiFi::create();
} catch (WiFiException $exception) {
    $scanError = $exception->getMessage();
    $wifi = null;
} catch (Throwable $exception) {
    error_log(sprintf('provision create failed: %s: %s', $exception::class, $exception->getMessage()));
    $scanError = $genericError;
    $wifi = null;
}

if ($wifi !== null) {
    $cachePath = getenv('PROVISION_CACHE') ?: '/run/php-wifi-provision/networks.json';
    $networks = ($readCache)($cachePath);
    $fromCache = $networks !== null;

    if (!$fromCache) {
        $networks = [];

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
        } catch (Throwable $exception) {
            error_log(sprintf('provision scan failed: %s: %s', $exception::class, $exception->getMessage()));
            $scanError = $genericError;
        }
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

            // NetworkManager's scan list is empty while the radio runs the
            // AP, so a join fails outright. Stop the AP and give the radio
            // a moment to leave AP mode before it can scan and join.
            if ($wifi->supports(SupportsHotspot::class) && $wifi->isHotspotActive()) {
                $wifi->stopHotspot();
                sleep(3);
            }

            $wifi->connect($ssid, $credentials);

            $donePath = getenv('PROVISION_DONE') ?: '/run/php-wifi-provision/done';

            if (@file_put_contents($donePath, '') === false) {
                error_log(sprintf('provision: cannot write the done marker "%s"', $donePath));
            }

            $device = '(unknown device)';

            try {
                $device = $wifi->device()->name;
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'provision device lookup failed: %s: %s',
                    $exception::class,
                    $exception->getMessage(),
                ));
            }

            $connected = ['device' => $device, 'ssid' => $ssid];
        } catch (WiFiException $exception) {
            $connectError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log(sprintf('provision connect failed: %s: %s', $exception::class, $exception->getMessage()));
            $connectError = $genericError;
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
<?php if ($fromCache) : ?>
<p>Scanned before the hotspot started.</p>
<?php endif; ?>
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
