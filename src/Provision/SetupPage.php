<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Watchdog\Clock;
use Sanchescom\WiFi\Watchdog\SystemClock;

/**
 * The page `wifi provision` serves on its setup hotspot: one application
 * built from {@see Portal}.
 *
 * Two things set it apart from an ordinary form.
 *
 * It is a captive portal. The hotspot's DNS answers every name with this
 * device, so the checks a phone makes right after joining
 * (`connectivitycheck.gstatic.com/generate_204`,
 * `captive.apple.com/hotspot-detect.html`) arrive here. Any request for a
 * host that is not this device is answered with a redirect to the page —
 * not the 204 or the "Success" the phone hoped for — and that is what makes
 * it open the page by itself.
 *
 * And it answers before it acts. A single radio cannot stay an access point
 * and join a network, so the phone loses this page the moment the join
 * starts and can never be told how it went. The answer to the form therefore
 * says what happens next and where to look, and only once it has been sent
 * does the join run ({@see Response::$after}). If the join fails the hotspot
 * comes back, and the page then shows why.
 */
final class SetupPage
{
    /** Seconds the answer is given to reach the phone before the hotspot goes down under it. */
    private const ANSWER_HEAD_START = 1;

    /**
     * @param string $url this page's own address on the hotspot
     * @param ?string $localName the name the device answers to once it is on the network, e.g. "pi.local"
     */
    public function __construct(
        private readonly Portal $portal,
        private readonly string $url = 'http://' . HotspotConfig::ADDRESS . '/',
        private readonly ?string $localName = null,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /** @param array<mixed> $input the request's form fields */
    public function handle(string $method, string $host, string $path, array $input = []): Response
    {
        if (!$this->isOwnHost($host)) {
            return Response::redirect($this->url);
        }

        $api = $this->portal->handle($method, $path, $input);

        if ($api !== null) {
            return $api;
        }

        if ($path !== '/') {
            return Response::redirect($this->url);
        }

        if ($method === 'POST') {
            return $this->join($input);
        }

        return Response::html($this->page($this->form()));
    }

    private function isOwnHost(string $host): bool
    {
        $name = strtolower((string) preg_replace('/:\d+$/', '', $host));
        $own = strtolower((string) parse_url($this->url, PHP_URL_HOST));

        return $name === $own || ($this->localName !== null && $name === strtolower($this->localName));
    }

    /** @param array<mixed> $input */
    private function join(array $input): Response
    {
        $ssid = $input['ssid'] ?? null;
        $password = $input['password'] ?? '';

        if (!is_string($ssid) || $ssid === '' || !is_string($password)) {
            return Response::html(
                $this->page('<p class="bad">Choose a network from the list.</p>' . $this->form()),
                400,
            );
        }

        $where = $this->localName !== null
            ? sprintf(' and can be reached at <b>http://%s/</b>', self::escape($this->localName))
            : '';

        $body = sprintf(
            '<p>The device is now joining <b>%s</b>. This setup network is about to disappear.</p>'
            . '<p>If it does not come back within a minute, the device is on <b>%s</b>%s.'
            . ' Put this phone back on that network.</p>'
            . '<p>If it does come back, the join failed. Join the setup network again and this page will say why.</p>',
            self::escape($ssid),
            self::escape($ssid),
            $where,
        );

        return Response::html($this->page($body), 200, function () use ($ssid, $password): void {
            $this->clock->sleep(self::ANSWER_HEAD_START);
            $this->portal->connect($ssid, $password);
        });
    }

    private function form(): string
    {
        $status = $this->portal->status();
        $html = '';

        if ($status['lastAttempt'] !== null && !$status['lastAttempt']['ok']) {
            $html .= sprintf(
                '<p class="bad">Could not join <b>%s</b>: %s</p>',
                self::escape($status['lastAttempt']['ssid']),
                self::escape($status['lastAttempt']['message'] ?? 'the device could not join the network.'),
            );
        }

        $networks = $this->portal->networks();

        if ($networks === []) {
            return $html . '<p>No networks were found. Restart the setup to scan again.</p>';
        }

        $html .= '<form method="post" action="/">';

        foreach ($networks as $network) {
            $details = array_filter([
                $network['band'] !== null ? $network['band'] . ' GHz' : null,
                $network['quality'] !== null ? $network['quality'] . '%' : null,
                $network['security'],
            ]);

            $html .= sprintf(
                '<label><input type="radio" name="ssid" value="%s" required> %s <small>%s</small></label>',
                self::escape($network['ssid']),
                self::escape($network['ssid']),
                self::escape(implode(' · ', $details)),
            );
        }

        return $html
            . '<input type="password" name="password" placeholder="Password (leave empty if open)" autocomplete="off">'
            . '<button type="submit">Connect</button></form>';
    }

    private function page(string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Wi-Fi setup</title><style>'
            . 'body{font:16px/1.4 sans-serif;margin:0;padding:1rem;background:#111;color:#eee}h1{font-size:1.2rem}'
            . 'label{display:block;padding:.6rem;border:1px solid #333;border-radius:.4rem;margin-bottom:.5rem}'
            . 'small{color:#999}input[type=password],button{width:100%;box-sizing:border-box;padding:.7rem;'
            . 'margin-top:.5rem;font-size:1rem;border-radius:.4rem;border:1px solid #444}'
            . 'button{background:#2a6;color:#fff;border:0}.bad{color:#f88}'
            . '</style></head><body><h1>Wi-Fi setup</h1>' . $body . '</body></html>';
    }

    /** Network names come from whoever is in radio range; nothing reaches the page unescaped. */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
