<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Provision\Portal;

final class PortalTest extends ProvisionTestCase
{
    #[Test]
    public function networks_are_scanned_strongest_first_one_row_per_name(): void
    {
        $networks = (new Portal($this->wifi($this->runner())))->networks();

        $this->assertSame(
            ['ssid' => 'AlphaNet-foiEmE', 'band' => '2.4', 'quality' => 72, 'security' => 'WPA2'],
            $networks[0],
        );
        $this->assertSame(count($networks), count(array_unique(array_column($networks, 'ssid'))));
    }

    /** With the hotspot up there is nothing to scan with; the list taken beforehand is the list. */
    #[Test]
    public function cached_networks_are_served_without_scanning(): void
    {
        $runner = $this->runner();
        $row = ['ssid' => 'Home', 'band' => '5', 'quality' => 90, 'security' => 'WPA2'];
        $this->state()->cacheNetworks([$row]);

        $this->assertSame([$row], (new Portal($this->wifi($runner), $this->state()))->networks());
        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function a_join_that_works_is_reported_and_recorded(): void
    {
        $portal = new Portal($this->wifi($this->runner()), $this->state(), $this->clock);

        $result = $portal->connect('AlphaNet-foiEmE', 'hunter2-hunter2');

        $this->assertSame(['ok' => true, 'ssid' => 'AlphaNet-foiEmE', 'reason' => null, 'message' => null], $result);
        $this->assertSame('AlphaNet-foiEmE', $this->state()->joinedSsid());
        $this->assertFalse($this->state()->isJoining());
    }

    /** One radio: the hotspot goes first, the radio is given a moment, and only then is the network looked for. */
    #[Test]
    public function a_hotspot_that_is_up_is_stopped_before_the_join(): void
    {
        $runner = $this->runner(['connection show --active' => "Hotspot\n"]);
        $portal = new Portal($this->wifi($runner), $this->state(), $this->clock);

        $portal->connect('AlphaNet-foiEmE');

        $commands = self::commands($runner);
        $down = array_search('nmcli connection down Hotspot', $commands, true);
        $scan = current(array_keys(array_filter($commands, static fn ($c) => str_contains($c, 'device wifi list'))));

        $this->assertIsInt($down);
        $this->assertGreaterThan($down, $scan);
        $this->assertSame(1_003, $this->clock->now());
    }

    #[Test]
    public function a_wrong_passphrase_is_named_as_such(): void
    {
        $runner = $this->runner(['device wifi connect' => [
            'output' => self::FIXTURES . '/ConnectWrongPassphrase.txt',
            'exit' => 3,
            'stderr' => "Error: Timeout 10 sec expired.\n",
        ]]);
        $portal = new Portal($this->wifi($runner), $this->state(), $this->clock);

        $result = $portal->connect('AlphaNet-foiEmE', 'typo-typo');

        $this->assertFalse($result['ok']);
        $this->assertSame('wrong_passphrase', $result['reason']);
        $this->assertSame('The passphrase for "AlphaNet-foiEmE" was not accepted.', $result['message']);
        $this->assertSame('wrong_passphrase', $this->state()->lastAttempt()['reason'] ?? null);
        $this->assertNull($this->state()->joinedSsid());
    }

    #[Test]
    public function a_network_that_is_not_in_range_is_named_as_such(): void
    {
        $result = (new Portal($this->wifi($this->runner())))->connect('NoSuchNet');

        $this->assertSame('network_not_found', $result['reason']);
    }

    /** The command line and the tool's output are for the device's log, not for whoever holds the phone. */
    #[Test]
    public function an_unexplained_failure_tells_the_phone_nothing_about_the_command(): void
    {
        $runner = $this->runner(['device wifi connect' => [
            'output' => '',
            'exit' => 4,
            'stderr' => 'Error: Connection activation failed: (1) Unknown error.',
        ]]);
        $log = (string) ini_set('error_log', '/dev/null');

        try {
            $result = (new Portal($this->wifi($runner)))->connect('AlphaNet-foiEmE', 'hunter2-hunter2');
        } finally {
            ini_set('error_log', $log);
        }

        $this->assertSame('failed', $result['reason']);
        $this->assertSame('The device could not join the network.', $result['message']);
    }

    #[Test]
    public function status_never_scans_while_the_hotspot_is_up(): void
    {
        $runner = $this->runner(['connection show --active' => "Hotspot\n"]);

        $status = (new Portal($this->wifi($runner), $this->state()))->status();

        $this->assertTrue($status['hotspot']);
        $this->assertNull($status['connected']);

        foreach (self::commands($runner) as $command) {
            $this->assertStringNotContainsString('device wifi list', $command);
        }
    }

    #[Test]
    public function status_names_the_network_the_device_is_on(): void
    {
        $status = (new Portal($this->wifi($this->runner())))->status();

        $this->assertFalse($status['hotspot']);
        $this->assertSame('AlphaNet-foiEmE', $status['connected']);
    }

    #[Test]
    public function the_three_endpoints_answer_in_json_and_anything_else_is_left_to_the_caller(): void
    {
        $portal = new Portal($this->wifi($this->runner()), $this->state(), $this->clock);

        $networks = $portal->handle('GET', '/api/networks');
        $this->assertNotNull($networks);
        $this->assertSame('application/json', $networks->headers['Content-Type']);
        $this->assertSame('AlphaNet-foiEmE', json_decode($networks->body, true)['networks'][0]['ssid']);

        $status = $portal->handle('GET', '/api/status');
        $this->assertNotNull($status);
        $this->assertSame('AlphaNet-foiEmE', json_decode($status->body, true)['connected']);

        $connect = $portal->handle('POST', '/api/connect', ['ssid' => 'AlphaNet-foiEmE', 'password' => 'hunter2-hunter2']);
        $this->assertNotNull($connect);
        $this->assertSame(200, $connect->status);
        $this->assertTrue(json_decode($connect->body, true)['ok']);

        $this->assertNull($portal->handle('GET', '/'));
        $this->assertNull($portal->handle('GET', '/api/connect'));
    }

    #[Test]
    public function connect_without_a_network_is_a_bad_request_and_touches_nothing(): void
    {
        $runner = $this->runner();
        $portal = new Portal($this->wifi($runner), $this->state());

        foreach ([[], ['ssid' => ''], ['ssid' => ['a']], ['ssid' => 'Home', 'password' => ['x']]] as $input) {
            $response = $portal->handle('POST', '/api/connect', $input);
            $this->assertNotNull($response);
            $this->assertSame(400, $response->status);
        }

        $this->assertSame([], $runner->commands);
        $this->assertNull($this->state()->lastAttempt());
    }

    #[Test]
    public function a_failed_join_is_an_unprocessable_request(): void
    {
        $portal = new Portal($this->wifi($this->runner()));

        $response = $portal->handle('POST', '/api/connect', ['ssid' => 'NoSuchNet']);

        $this->assertNotNull($response);
        $this->assertSame(422, $response->status);
        $this->assertSame('network_not_found', json_decode($response->body, true)['reason']);
    }
}
