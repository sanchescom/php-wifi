<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use Sanchescom\WiFi\Backend\SupportsHotspot;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\NoAddress;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\WrongPassphrase;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;
use Sanchescom\WiFi\Watchdog\Clock;
use Sanchescom\WiFi\Watchdog\SystemClock;
use Sanchescom\WiFi\WiFi;
use Throwable;

/**
 * The three things a Wi-Fi setup screen needs — which networks are there,
 * join this one, how did it go — as plain methods and as JSON endpoints, with
 * no framework underneath. `wifi provision` is one application built on it;
 * any PHP app can be another, by calling the methods or by handing its
 * requests under `/api/` to {@see self::handle()}.
 *
 * It has no authentication of its own, and {@see self::connect()} changes
 * which network the device is on: put it behind the application's own.
 */
final class Portal
{
    /** Seconds the radio is given to leave access-point mode before it scans and joins. */
    private const RADIO_SETTLE_SECONDS = 3;

    /** How often, and how many seconds apart, the network is looked for once a hotspot has been stopped. */
    private const SCANS_AFTER_HOTSPOT = 8;

    private const SCAN_INTERVAL_SECONDS = 2;

    public function __construct(
        private readonly WiFi $wifi,
        private readonly ?State $state = null,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * The networks in range, strongest first, one row per name. While a
     * hotspot is up a single radio cannot scan, so the rows a
     * {@see State} cached beforehand are returned when there are any.
     *
     * @return list<array{ssid: string, band: ?string, quality: ?int, security: string}>
     */
    public function networks(): array
    {
        return $this->state?->cachedNetworks() ?? self::describe($this->wifi->scan());
    }

    /** @return list<array{ssid: string, band: ?string, quality: ?int, security: string}> */
    public static function describe(NetworkCollection $networks): array
    {
        $rows = [];

        foreach ($networks->uniqueBySsid()->sortBySignal() as $network) {
            /** @var Network $network */
            if ($network->ssidHidden || $network->ssid === '') {
                continue;
            }

            $rows[] = [
                'ssid' => $network->ssid,
                'band' => $network->band?->value,
                'quality' => $network->signal !== null ? (int) round($network->signal->quality) : null,
                'security' => $network->security->value,
            ];
        }

        return $rows;
    }

    /**
     * Joins $ssid and says how it went. Never throws: the reason is one of
     * `wrong_passphrase`, `network_not_found`, `no_address`,
     * `permission_denied`, `invalid` or `failed`, with a sentence fit to show
     * the person holding the phone. What exactly went wrong in the `failed`
     * case goes to the error log, not into the answer.
     *
     * A hotspot this device is serving is stopped first: a single radio
     * cannot stay an access point and join a network.
     *
     * @return array{ok: bool, ssid: string, reason: ?string, message: ?string}
     */
    public function connect(string $ssid, string $password = ''): array
    {
        $this->state?->beginAttempt();

        [$reason, $message] = $this->join($ssid, $password);

        $this->state?->endAttempt($ssid, $reason === null, $reason, $message);

        return ['ok' => $reason === null, 'ssid' => $ssid, 'reason' => $reason, 'message' => $message];
    }

    /**
     * @return array{
     *     hotspot: bool,
     *     joining: bool,
     *     connected: ?string,
     *     lastAttempt: array{at: int, ssid: string, ok: bool, reason: ?string, message: ?string}|null,
     * }
     */
    public function status(): array
    {
        $hotspot = $this->wifi->supports(SupportsHotspot::class) && $this->wifi->isHotspotActive();
        $connected = null;

        if (!$hotspot) {
            // Never while the hotspot is up: the scan would have to drop it.
            $network = $this->wifi->scan()->connected()->first();
            $connected = $network instanceof Network ? $network->ssid : null;
        }

        return [
            'hotspot' => $hotspot,
            'joining' => $this->state?->isJoining() ?? false,
            'connected' => $connected,
            'lastAttempt' => $this->state?->lastAttempt(),
        ];
    }

    /**
     * `GET /api/networks`, `GET /api/status`, `POST /api/connect` (fields
     * `ssid` and `password`). Null for anything else, so the caller can go on
     * to its own routes.
     *
     * @param array<mixed> $input the request's form or JSON fields
     */
    public function handle(string $method, string $path, array $input = []): ?Response
    {
        return match ([$method, $path]) {
            ['GET', '/api/networks'] => Response::json(['networks' => $this->networks()]),
            ['GET', '/api/status'] => Response::json($this->status()),
            ['POST', '/api/connect'] => $this->handleConnect($input),
            default => null,
        };
    }

    /** @param array<mixed> $input */
    private function handleConnect(array $input): Response
    {
        $ssid = $input['ssid'] ?? null;
        $password = $input['password'] ?? '';

        if (!is_string($ssid) || $ssid === '' || !is_string($password)) {
            return Response::json(
                ['ok' => false, 'ssid' => '', 'reason' => 'invalid', 'message' => 'Choose a network.'],
                400,
            );
        }

        $result = $this->connect($ssid, $password);

        return Response::json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * The network as the scan shows it. Right after a hotspot has gone down
     * one look is not enough: NetworkManager empties its list of networks
     * when the radio leaves access-point mode and fills it again from a scan
     * of its own, and for a moment in between `nmcli device wifi list` prints
     * nothing at all — three seconds after the stop on one run on the Pi, not
     * on the next. So the scan is repeated until the network is in it.
     *
     * @throws NetworkNotFound when the network is in none of $scans scans
     */
    private function find(string $ssid, int $scans): Network
    {
        for ($scan = 1;; $scan++) {
            try {
                return $this->wifi->scan()->bySsid($ssid);
            } catch (NetworkNotFound $exception) {
                if ($scan >= $scans) {
                    throw $exception;
                }

                $this->clock->sleep(self::SCAN_INTERVAL_SECONDS);
            }
        }
    }

    /** @return array{0: ?string, 1: ?string} reason and message, both null on success */
    private function join(string $ssid, string $password): array
    {
        try {
            $scans = 1;

            if ($this->wifi->supports(SupportsHotspot::class) && $this->wifi->isHotspotActive()) {
                $this->wifi->stopHotspot();
                $this->clock->sleep(self::RADIO_SETTLE_SECONDS);
                $scans = self::SCANS_AFTER_HOTSPOT;
            }

            $this->wifi->connect(
                $this->find($ssid, $scans),
                $password === '' ? Credentials::none() : Credentials::password($password),
            );

            return [null, null];
        } catch (WrongPassphrase $exception) {
            return ['wrong_passphrase', $exception->getMessage()];
        } catch (NoAddress $exception) {
            return ['no_address', $exception->getMessage()];
        } catch (NetworkNotFound $exception) {
            return ['network_not_found', $exception->getMessage()];
        } catch (PermissionDenied) {
            return ['permission_denied', 'The device is not allowed to change its Wi-Fi settings.'];
        } catch (InvalidArgument $exception) {
            return ['invalid', $exception->getMessage()];
        } catch (Throwable $exception) {
            error_log(sprintf('php-wifi portal: join failed: %s: %s', $exception::class, $exception->getMessage()));

            return ['failed', 'The device could not join the network.'];
        }
    }
}
