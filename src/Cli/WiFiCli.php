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
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;
use Sanchescom\WiFi\Watchdog\SystemClock;
use Sanchescom\WiFi\Watchdog\Watchdog;
use Sanchescom\WiFi\Watchdog\WatchdogConfig;
use Sanchescom\WiFi\Watchdog\WatchdogState;
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

    /**
     * The CommandRunner the WiFi facade's backend was built with, when the
     * WIFI_FAKE_RUNNER test hook is active; null in production. Reused for
     * {@see Watchdog}'s own `iw` invocations so a test's fixtures govern
     * every command the watch command issues, not just the ones the facade
     * makes on its own.
     */
    private readonly ?CommandRunner $commandRunner;

    public function __construct()
    {
        self::normalizeHotspotArgv();

        parent::__construct();

        [$this->wifi, $this->commandRunner] = self::buildWifi();
    }

    protected function setup(Options $options): void
    {
        $options->setHelp('Cross-platform php utility for working with wi-fi networks');

        $options->registerCommand('list', 'Show surrounding wifi networks');
        $options->registerOption('unique', 'One row per SSID (strongest radio)', 'u', false, 'list');
        $options->registerOption('connected', 'Show only connected networks', 'c', false, 'list');
        $options->registerOption('json', 'Print JSON instead of a table', null, false, 'list');

        $options->registerCommand('connect', 'Connect to a wifi network');
        $options->registerOption('ssid', 'SSID of the network', null, true, 'connect');
        $options->registerOption('bssid', 'BSSID of the network', null, true, 'connect');
        $options->registerOption('password', 'Password of the network', null, true, 'connect');
        $options->registerOption(
            'password-file',
            'Read the password from a file, or from stdin when given "-"',
            null,
            true,
            'connect',
        );
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
        $options->registerOption(
            'password-file',
            'Read the password from a file, or from stdin when given "-"',
            null,
            true,
            'hotspot',
        );
        $options->registerOption('band', 'Radio band: 2.4 or 5', null, true, 'hotspot');
        $options->registerOption(
            'device',
            'Which device to use (auto-detected when omitted)',
            null,
            true,
            'hotspot',
        );

        $options->registerCommand(
            'watch',
            'Keep rejoining a wifi network, raising a provisioning hotspot when it cannot',
        );
        $options->registerOption(
            'ssid',
            'Target SSID to keep rejoining (default: any known network)',
            null,
            true,
            'watch',
        );
        $options->registerOption('interval', 'Seconds between checks (default: 30)', null, true, 'watch');
        $options->registerOption(
            'retry',
            'Seconds an idle, unattended hotspot is left up before retrying (default: 300)',
            null,
            true,
            'watch',
        );
        $options->registerOption('hotspot-ssid', 'SSID of the hotspot to raise', null, true, 'watch');
        $options->registerOption(
            'hotspot-password-file',
            'Read the hotspot passphrase from a file, or from stdin when given "-"',
            null,
            true,
            'watch',
        );
        $options->registerOption(
            'device',
            'Which device to use (auto-detected when omitted)',
            null,
            true,
            'watch',
        );
        $options->registerOption(
            'once',
            'Run a single check, print the resulting state and exit, instead of looping forever',
            null,
            false,
            'watch',
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
            'watch' => $this->cmdWatch($options),
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

        if ($options->getOpt('json')) {
            $this->printListJson($networks);

            return;
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
     * Prints one JSON object per network to stdout, pretty-printed with a
     * trailing newline. `JSON_THROW_ON_ERROR` turns an encoding failure into
     * an exception the CLI maps to exit 1, rather than requiring a `false`
     * check on `json_encode`'s return value.
     */
    private function printListJson(NetworkCollection $networks): void
    {
        $rows = [];

        foreach ($networks as $network) {
            $rows[] = [
                'ssid' => $network->ssid,
                'hidden' => $network->ssidHidden,
                'bssid' => $network->bssid !== null ? (string) $network->bssid : null,
                'channel' => $network->channel,
                'band' => $network->band?->value,
                'frequency' => $network->frequency,
                'quality' => $network->signal !== null ? (int) round($network->signal->quality) : null,
                'dbm' => $network->signal !== null ? (int) round($network->signal->dbm) : null,
                'security' => $network->security->value,
                'securityFlags' => $network->securityFlags,
                'connected' => $network->connected,
            ];
        }

        echo json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
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

        $passwordOpt = $this->resolvePassword($options);

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

    /**
     * `--once` runs a single {@see Watchdog::tick()} and prints the
     * resulting state's backing value, for both interactive checking and
     * live verification. Without it, {@see Watchdog::run()} loops forever —
     * a command this method never returns from, so no test may take that
     * branch — logging every tick worth an operator's attention to STDERR
     * via {@see self::watchLogger()}.
     */
    private function cmdWatch(Options $options): void
    {
        if (!$this->wifi->supports(SupportsHotspot::class)) {
            throw UnsupportedOperation::by($this->wifi->backend()::class, SupportsHotspot::class);
        }

        $hotspotSsidOpt = $this->optString($options, 'hotspot-ssid');

        if ($hotspotSsidOpt === false) {
            throw new InvalidArgument('watch: --hotspot-ssid is required.');
        }

        $hotspotPasswordOpt = $this->resolvePassword($options, 'hotspot-password-file', null);

        [$device] = $this->resolveDevice($options);

        $hotspot = new HotspotConfig(
            ssid: $hotspotSsidOpt,
            password: $hotspotPasswordOpt !== false ? $hotspotPasswordOpt : '',
            device: $device,
        );

        $ssidOpt = $this->optString($options, 'ssid');

        $config = new WatchdogConfig(
            ssid: $ssidOpt !== false ? $ssidOpt : null,
            hotspot: $hotspot,
            interval: $this->optInt($options, 'interval', 30),
            retryAfter: $this->optInt($options, 'retry', 300),
            device: $device,
        );

        $watchdog = new Watchdog($this->wifi, $config, new SystemClock(), $this->commandRunner);

        if ($options->getOpt('once')) {
            echo $watchdog->tick()->value . PHP_EOL;

            return;
        }

        $watchdog->run($this->watchLogger($watchdog));
    }

    /**
     * Builds the observer passed to {@see Watchdog::run()}: one timestamped
     * line to STDERR (so it lands in the journal under systemd) per tick an
     * operator needs to see.
     *
     * Not every tick is logged: at the default 30s interval, logging every
     * `connected` tick forever during ordinary, uneventful operation would
     * bury the ticks that matter in noise. Instead every state other than
     * Connected is logged unconditionally — a hotspot going up, staying
     * busy, or a failure are all worth a line each time — and Connected is
     * logged only the first time it is reached after some other state, the
     * "we're back" line, not on every tick that follows while nothing
     * changes. A line also carries {@see Watchdog::lastError()} when there
     * is one — a failure, or a hotspot held up because its stations could not
     * be counted — so the journal says why, not just what.
     */
    private function watchLogger(Watchdog $watchdog): callable
    {
        $previous = null;

        return static function (WatchdogState $state) use ($watchdog, &$previous): void {
            $changed = $state !== $previous;
            $previous = $state;

            if ($state === WatchdogState::Connected && !$changed) {
                return;
            }

            $detail = $watchdog->lastError() !== null
                ? ': ' . $watchdog->lastError()
                : '';

            fwrite(STDERR, sprintf('%s watch: %s%s', date(DATE_ATOM), $state->value, $detail) . PHP_EOL);
        };
    }

    /**
     * An integer option's value, or $default when it was not given. A
     * non-numeric or non-positive value is not rejected here: it is handed
     * straight to {@see WatchdogConfig}, whose own constructor already
     * rejects anything <= 0 (a non-numeric string casts to 0), so the
     * validation is not duplicated.
     */
    private function optInt(Options $options, string $name, int $default): int
    {
        $value = $this->optString($options, $name);

        return $value !== false ? (int) $value : $default;
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
        $passwordOpt = $this->resolvePassword($options);

        return $passwordOpt !== false ? Credentials::password($passwordOpt) : Credentials::none();
    }

    /**
     * Resolves a --password / --password-file pair. `false` means no
     * password was given at all (an open network for connect; an empty
     * passphrase, rejected by HotspotConfig, for hotspot and watch).
     *
     * $inlineOption is null for `watch`'s hotspot passphrase, which has no
     * inline `--hotspot-password` option at all — a passphrase must come
     * from a file or stdin, never argv.
     */
    private function resolvePassword(
        Options $options,
        string $fileOption = 'password-file',
        ?string $inlineOption = 'password',
    ): string|false {
        $inline = $inlineOption !== null ? $this->optString($options, $inlineOption) : false;
        $file = $this->optString($options, $fileOption);

        if ($inline !== false && $file !== false) {
            throw new InvalidArgument(sprintf('Use --%s or --%s, not both.', $inlineOption, $fileOption));
        }

        if ($inline !== false) {
            return $inline;
        }

        if ($file === false) {
            return false;
        }

        if ($file === '-') {
            if (stream_isatty(STDIN)) {
                throw new InvalidArgument(sprintf('--%s=- expects the password on stdin.', $fileOption));
            }

            $contents = stream_get_contents(STDIN);
        } else {
            if ($file === '') {
                throw new InvalidArgument(
                    sprintf('--%s needs a path, or "-" to read the password from stdin.', $fileOption),
                );
            }

            if (is_dir($file)) {
                throw new InvalidArgument(sprintf('The password file "%s" is a directory.', $file));
            }

            $contents = @file_get_contents($file);
        }

        if ($contents === false) {
            throw new InvalidArgument(sprintf('Cannot read the password file "%s".', $file));
        }

        $password = preg_replace('/\r?\n$/', '', $contents) ?? $contents;

        if ($password === '') {
            throw new InvalidArgument(
                $file === '-' ? 'No password arrived on stdin.' : sprintf('The password file "%s" is empty.', $file),
            );
        }

        return $password;
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

    /**
     * Builds the facade, wiring a FakeCommandRunner when the test hook is
     * active. Also returns that runner (null in production) so it can be
     * handed to a {@see Watchdog} too: the watch command's own `iw`
     * invocations must be driven by the same fixtures as the facade's.
     *
     * @return array{0: WiFi, 1: ?CommandRunner}
     */
    private static function buildWifi(): array
    {
        $fakeRunnerDir = getenv('WIFI_FAKE_RUNNER');
        $fakeOsName = getenv('WIFI_FAKE_OS');

        if ($fakeRunnerDir === false || $fakeOsName === false) {
            return [WiFi::create(), null];
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

        return [new WiFi(BackendFactory::forOs(Os::from($fakeOsName), $runner)), $runner];
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
