<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Cli;

use LucidFrame\Console\ConsoleTable;
use Sanchescom\WiFi\Backend\BackendFactory;
use Sanchescom\WiFi\Backend\NetworksetupBackend;
use Sanchescom\WiFi\Backend\SupportsHotspot;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;
use Sanchescom\WiFi\WiFi;
use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;
use Throwable;

/**
 * Cross-platform CLI for scanning and joining Wi-Fi networks, on top of the
 * WiFi object facade. Invoked from the `bin/wifi` bootstrap script.
 */
final class WiFiCli extends CLI
{
    private readonly WiFi $wifi;

    public function __construct()
    {
        self::normalizeHotspotArgv();

        parent::__construct();

        $this->wifi = self::buildWifi();
    }

    protected function setup(Options $options): void
    {
        $options->setHelp('Cross-platform php utility for working with wi-fi networks');

        $options->registerCommand('list', 'Show surrounding wifi networks');
        $options->registerOption('unique', 'One row per SSID (strongest radio)', 'u', false, 'list');
        $options->registerOption('connected', 'Show only connected networks', 'c', false, 'list');

        $options->registerCommand('connect', 'Connect to a wifi network');
        $options->registerOption('ssid', 'SSID of the network', null, true, 'connect');
        $options->registerOption('bssid', 'BSSID of the network', null, true, 'connect');
        $options->registerOption('password', 'Password of the network', null, true, 'connect');
        $options->registerOption(
            'device',
            'Which device to use (auto-detected when omitted)',
            null,
            true,
            'connect',
        );

        $options->registerCommand('disconnect', 'Disconnect from the current wifi network');
        $options->registerOption(
            'device',
            'Which device to use (auto-detected when omitted)',
            null,
            true,
            'disconnect',
        );

        $options->registerCommand('device', 'Show the detected wifi device');

        $options->registerCommand('known', 'List known (saved) networks');

        $options->registerCommand(
            'forget',
            'Forget a known network. A name starting with "-" must be passed after --.',
        );
        $options->registerArgument('ssid-or-name', 'SSID or connection name to forget', false, 'forget');

        $options->registerCommand('hotspot', 'Start, stop or check the status of a wifi hotspot');
        $options->registerArgument('action', 'start, stop or status', false, 'hotspot');
        $options->registerOption('ssid', 'SSID of the hotspot', null, true, 'hotspot');
        $options->registerOption('password', 'Password of the hotspot', null, true, 'hotspot');
        $options->registerOption('band', 'Radio band: 2.4 or 5', null, true, 'hotspot');
        $options->registerOption(
            'device',
            'Which device to use (auto-detected when omitted)',
            null,
            true,
            'hotspot',
        );
    }

    protected function main(Options $options): void
    {
        try {
            $this->dispatch($options);
        } catch (UnsupportedOperation $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            exit(2);
        } catch (PermissionDenied $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            exit(3);
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    private function dispatch(Options $options): void
    {
        match ($options->getCmd()) {
            'list' => $this->cmdList($options),
            'connect' => $this->cmdConnect($options),
            'disconnect' => $this->cmdDisconnect($options),
            'device' => $this->cmdDevice(),
            'known' => $this->cmdKnown(),
            'forget' => $this->cmdForget($options),
            'hotspot' => $this->cmdHotspot($options),
            default => throw new InvalidArgument('No known command was given; see --help.'),
        };
    }

    private function cmdList(Options $options): void
    {
        $networks = $this->wifi->scan();

        if ($options->getOpt('unique')) {
            $networks = $networks->uniqueBySsid();
        }

        if ($options->getOpt('connected')) {
            $networks = $networks->connected();
        }

        if ($networks->hasHiddenSsids()) {
            fwrite(STDERR, $this->hiddenSsidHint($networks) . PHP_EOL);
        }

        $table = new ConsoleTable();
        $table->setHeaders(
            ['SSID', 'BSSID', 'Channel', 'Band', 'Quality', 'dBm', 'Frequency', 'Connected', 'Security'],
        );

        foreach ($networks as $network) {
            $table->addRow([
                $network->ssidHidden || $network->ssid === '' ? '-' : $network->ssid,
                $network->bssid !== null ? (string) $network->bssid : '-',
                $network->channel !== null ? (string) $network->channel : '-',
                $network->band->value ?? '-',
                $network->signal !== null ? sprintf('%d%%', (int) round($network->signal->quality)) : '-',
                $network->signal !== null ? sprintf('%.0f', $network->signal->dbm) : '-',
                $network->frequency !== null ? (string) $network->frequency : '-',
                $network->connected ? 'true' : 'false',
                $network->security->value,
            ]);
        }

        $table->hideBorder();
        $table->display();
    }

    /**
     * On macOS, a hidden SSID is indistinguishable from every other name
     * Location Services redacts, so the hint names the real cause. On every
     * other backend an empty SSID means the access point itself broadcasts
     * no name, which the OS reports honestly.
     */
    private function hiddenSsidHint(NetworkCollection $networks): string
    {
        if ($this->wifi->backend() instanceof NetworksetupBackend) {
            return 'Network names were hidden by macOS. Grant Location Services to the process running PHP'
                . ' to see SSIDs.';
        }

        $hidden = $networks->filter(fn (Network $network): bool => $network->ssidHidden)->count();

        return sprintf('%d network(s) broadcast no SSID (hidden); they are shown as "-".', $hidden);
    }

    private function cmdConnect(Options $options): void
    {
        $bssidOpt = $this->optString($options, 'bssid');

        [$device, $auto] = $this->resolveDevice($options);
        $credentials = $this->resolveCredentials($options);

        if ($bssidOpt !== false) {
            $network = $this->wifi->scan()->byBssid($bssidOpt);
            $this->wifi->connect($network, $credentials, $device);
            $ssid = $network->ssid;
        } else {
            $ssidOpt = $this->optString($options, 'ssid');

            if ($ssidOpt === false) {
                throw new InvalidArgument('connect requires --ssid or --bssid.');
            }

            $ssid = $ssidOpt;
            $this->wifi->connect($ssid, $credentials, $device);
        }

        echo sprintf('Connected to %s via %s%s', $ssid, $device, $auto ? ' (auto)' : '') . PHP_EOL;
    }

    private function cmdDisconnect(Options $options): void
    {
        [$device, $auto] = $this->resolveDevice($options);

        $this->wifi->disconnect($device);

        echo sprintf('Disconnected via %s%s', $device, $auto ? ' (auto)' : '') . PHP_EOL;
    }

    private function cmdDevice(): void
    {
        echo $this->wifi->device() . PHP_EOL;
    }

    private function cmdKnown(): void
    {
        $table = new ConsoleTable();
        $table->setHeaders(['Name', 'SSID', 'Device', 'Active']);

        foreach ($this->wifi->knownNetworks() as $network) {
            $table->addRow([
                $network->name,
                $network->ssid,
                $network->device !== null ? (string) $network->device : '-',
                $network->active ? 'true' : 'false',
            ]);
        }

        $table->hideBorder();
        $table->display();
    }

    private function cmdForget(Options $options): void
    {
        $args = $options->getArgs();
        $target = $args[0] ?? null;

        if (!is_string($target) || $target === '') {
            throw new InvalidArgument('forget requires an SSID or connection name argument.');
        }

        $this->wifi->forget($target);

        echo sprintf('Forgot %s.', $target) . PHP_EOL;
    }

    private function cmdHotspot(Options $options): void
    {
        $args = $options->getArgs();
        $action = $args[0] ?? '';

        match ($action) {
            'start' => $this->cmdHotspotStart($options),
            'stop' => $this->cmdHotspotStop(),
            'status' => $this->cmdHotspotStatus(),
            default => throw new InvalidArgument('hotspot requires an action: start, stop or status.'),
        };
    }

    private function cmdHotspotStart(Options $options): void
    {
        if (!$this->wifi->supports(SupportsHotspot::class)) {
            throw UnsupportedOperation::by($this->wifi->backend()::class, SupportsHotspot::class);
        }

        $bandOpt = $this->optString($options, 'band');
        $band = match ($bandOpt) {
            false => null,
            '2.4' => Band::GHz2_4,
            '5' => Band::GHz5,
            default => throw new InvalidArgument('--band must be "2.4" or "5".'),
        };

        $ssidOpt = $this->optString($options, 'ssid');

        if ($ssidOpt === false) {
            throw new InvalidArgument('hotspot start: --ssid is required.');
        }

        $passwordOpt = $this->optString($options, 'password');

        [$device, $auto] = $this->resolveDevice($options);

        $config = new HotspotConfig(
            ssid: $ssidOpt,
            password: $passwordOpt !== false ? $passwordOpt : '',
            band: $band,
            device: $device,
        );

        $hotspot = $this->wifi->startHotspot($config);

        echo sprintf('Hotspot %s started on %s%s', $hotspot->ssid, $hotspot->device, $auto ? ' (auto)' : '')
            . PHP_EOL;
    }

    private function cmdHotspotStop(): void
    {
        $this->wifi->stopHotspot();

        echo 'Hotspot stopped.' . PHP_EOL;
    }

    private function cmdHotspotStatus(): void
    {
        echo ($this->wifi->isHotspotActive() ? 'active' : 'inactive') . PHP_EOL;
    }

    /** @return array{0: Device, 1: bool} device and whether it was auto-detected */
    private function resolveDevice(Options $options): array
    {
        $deviceOpt = $this->optString($options, 'device');

        if ($deviceOpt === false) {
            return [$this->wifi->device(), true];
        }

        return [new Device($deviceOpt), false];
    }

    private function resolveCredentials(Options $options): Credentials
    {
        $passwordOpt = $this->optString($options, 'password');

        return $passwordOpt !== false ? Credentials::password($passwordOpt) : Credentials::none();
    }

    /**
     * A registered option's value, guaranteed to be a plain string rather
     * than the array splitbrain\phpcli's Options allows in general.
     *
     * @return string|false the value, or false when the option was not given
     */
    private function optString(Options $options, string $name): string|false
    {
        $value = $options->getOpt($name);

        if ($value === false) {
            return false;
        }

        if (!is_string($value)) {
            throw new InvalidArgument(sprintf('--%s must be given a single value.', $name));
        }

        return $value;
    }

    /**
     * Reads the WIFI_FAKE_RUNNER / WIFI_FAKE_OS test hook and turns a
     * "needle => fixture" map.json into the array FakeCommandRunner expects,
     * resolving string fixtures against the map's own directory.
     *
     * @return array<string, string|array{output: string, exit?: int, stderr?: string}>
     */
    private static function loadFixtureMap(string $directory): array
    {
        $path = rtrim($directory, '/') . '/map.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            fwrite(STDERR, sprintf('Cannot read fixture map "%s".', $path) . PHP_EOL);
            exit(1);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            fwrite(STDERR, sprintf('Fixture map "%s" must decode to a JSON object.', $path) . PHP_EOL);
            exit(1);
        }

        $fixtures = [];

        /** @var mixed $value */
        foreach ($decoded as $needle => $value) {
            if (!is_string($needle)) {
                continue;
            }

            if (is_string($value)) {
                $fixtures[$needle] = rtrim($directory, '/') . '/' . $value;
                continue;
            }

            if (is_array($value) && isset($value['output']) && is_string($value['output'])) {
                $entry = ['output' => $value['output']];

                if (isset($value['exit']) && is_int($value['exit'])) {
                    $entry['exit'] = $value['exit'];
                }

                if (isset($value['stderr']) && is_string($value['stderr'])) {
                    $entry['stderr'] = $value['stderr'];
                }

                $fixtures[$needle] = $entry;
            }
        }

        return $fixtures;
    }

    /** Builds the facade, wiring a FakeCommandRunner when the test hook is active. */
    private static function buildWifi(): WiFi
    {
        $fakeRunnerDir = getenv('WIFI_FAKE_RUNNER');
        $fakeOsName = getenv('WIFI_FAKE_OS');

        if ($fakeRunnerDir === false || $fakeOsName === false) {
            return WiFi::create();
        }

        if (!class_exists(FakeCommandRunner::class)) {
            fwrite(STDERR, 'WIFI_FAKE_RUNNER requires a dev install (tests/Support is not autoloaded).' . PHP_EOL);
            exit(1);
        }

        $logPath = getenv('WIFI_FAKE_LOG');
        $runner = new FakeCommandRunner(
            self::loadFixtureMap($fakeRunnerDir),
            $logPath === false || $logPath === '' ? null : $logPath,
        );

        return new WiFi(BackendFactory::forOs(Os::from($fakeOsName), $runner));
    }

    /**
     * splitbrain\phpcli's Options parser stops recognising `--options` as
     * soon as it meets the first plain argument, treating everything after
     * it as more plain arguments. That collides with
     * `wifi hotspot <action> --flags`, where the action is a plain argument
     * that precedes the flags, so the action token is moved to the end of
     * argv before Options ever sees it.
     */
    private static function normalizeHotspotArgv(): void
    {
        global $argv;

        if (($argv[1] ?? null) !== 'hotspot' || !isset($argv[2]) || $argv[2] === '' || $argv[2][0] === '-') {
            return;
        }

        $action = $argv[2];
        unset($argv[2]);
        $argv[] = $action;
        $argv = array_values($argv);
    }
}
