<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\Attributes\Test;
use Sanchescom\WiFi\Provision\Portal;
use Sanchescom\WiFi\Provision\SetupPage;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;

final class SetupPageTest extends ProvisionTestCase
{
    private function page(FakeCommandRunner $runner, ?string $localName = 'femus-pi.local'): SetupPage
    {
        return new SetupPage(
            new Portal($this->wifi($runner), $this->state(), $this->clock),
            'http://10.42.0.1/',
            $localName,
            $this->clock,
        );
    }

    /**
     * What makes a phone open the page by itself: its connectivity check,
     * sent to a host that is not this device, gets a redirect instead of the
     * 204 (Android) or the "Success" page (iOS) it was hoping for.
     */
    #[Test]
    public function a_request_for_any_other_host_is_sent_to_the_setup_page(): void
    {
        $runner = $this->runner();
        $page = $this->page($runner);

        foreach (
            [
                ['connectivitycheck.gstatic.com', '/generate_204'],
                ['captive.apple.com', '/hotspot-detect.html'],
                ['www.msftconnecttest.com', '/connecttest.txt'],
                ['example.com', '/'],
                ['', '/'],
            ] as [$host, $path]
        ) {
            $response = $page->handle('GET', $host, $path);

            $this->assertSame(302, $response->status, $host);
            $this->assertSame('http://10.42.0.1/', $response->headers['Location']);
        }

        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function the_device_is_itself_under_its_address_with_or_without_a_port_and_under_its_local_name(): void
    {
        $page = $this->page($this->runner());

        foreach (['10.42.0.1', '10.42.0.1:80', 'Femus-Pi.local', 'femus-pi.local:8080'] as $host) {
            $this->assertSame(200, $page->handle('GET', $host, '/')->status, $host);
        }
    }

    #[Test]
    public function an_unknown_path_on_the_device_leads_back_to_the_page(): void
    {
        $response = $this->page($this->runner())->handle('GET', '10.42.0.1', '/favicon.ico');

        $this->assertSame(302, $response->status);
    }

    #[Test]
    public function the_api_is_reachable_through_the_page(): void
    {
        $response = $this->page($this->runner())->handle('GET', '10.42.0.1', '/api/networks');

        $this->assertSame('application/json', $response->headers['Content-Type']);
    }

    /** A network name is chosen by whoever is in radio range, and it is printed on a page served by root. */
    #[Test]
    public function network_names_are_escaped(): void
    {
        $evil = '"><script>alert(1)</script>';
        $this->state()->cacheNetworks([['ssid' => $evil, 'band' => '2.4', 'quality' => 70, 'security' => 'WPA2']]);
        $this->state()->endAttempt($evil, false, 'failed', '<b>boom</b>');

        $body = $this->page($this->runner(['connection show --active' => "Hotspot\n"]))
            ->handle('GET', '10.42.0.1', '/')->body;

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringNotContainsString('<b>boom</b>', $body);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
        $this->assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $body);
    }

    #[Test]
    public function the_page_says_why_the_last_attempt_failed(): void
    {
        $this->state()->endAttempt('Home', false, 'wrong_passphrase', 'The passphrase for "Home" was not accepted.');

        $body = $this->page($this->runner(['connection show --active' => "Hotspot\n"]))
            ->handle('GET', '10.42.0.1', '/')->body;

        $this->assertStringContainsString(
            'Could not join <b>Home</b>: The passphrase for &quot;Home&quot; was not accepted.',
            $body,
        );
    }

    /**
     * The phone can only ever be told what is about to happen: the answer
     * has to be complete, and say where the device will be, before anything
     * touches the radio.
     */
    #[Test]
    public function the_form_is_answered_before_the_join_and_the_join_runs_afterwards(): void
    {
        $runner = $this->runner(['connection show --active' => "Hotspot\n"]);

        $response = $this->page($runner)->handle(
            'POST',
            '10.42.0.1',
            '/',
            ['ssid' => 'AlphaNet-foiEmE', 'password' => 'hunter2-hunter2'],
        );

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('now joining <b>AlphaNet-foiEmE</b>', $response->body);
        $this->assertStringContainsString('http://femus-pi.local/', $response->body);
        $this->assertStringNotContainsString('hunter2', $response->body);
        $this->assertSame([], $runner->commands, 'nothing may happen before the answer is sent');
        $this->assertNotNull($response->after);

        ($response->after)();

        $commands = self::commands($runner);
        $this->assertContains('nmcli connection down Hotspot', $commands);
        $this->assertStringContainsString('device wifi connect AlphaNet-foiEmE', (string) end($commands));
        $this->assertSame('AlphaNet-foiEmE', $this->state()->joinedSsid());
        // One second for the answer to leave, three for the radio to settle.
        $this->assertSame(1_004, $this->clock->now());
    }

    #[Test]
    public function without_a_local_name_the_answer_promises_none(): void
    {
        $response = $this->page($this->runner(), null)->handle('POST', '10.42.0.1', '/', ['ssid' => 'Home']);

        $this->assertStringNotContainsString('.local', $response->body);
    }

    #[Test]
    public function a_form_without_a_network_is_shown_again_and_nothing_is_joined(): void
    {
        $response = $this->page($this->runner())->handle('POST', '10.42.0.1', '/', ['password' => 'x']);

        $this->assertSame(400, $response->status);
        $this->assertNull($response->after);
        $this->assertStringContainsString('Choose a network from the list.', $response->body);
    }
}
