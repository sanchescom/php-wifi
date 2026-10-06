<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\Attributes\Test;

final class StateTest extends ProvisionTestCase
{
    private const ROW = ['ssid' => 'Home', 'band' => '2.4', 'quality' => 70, 'security' => 'WPA2'];

    #[Test]
    public function cached_networks_come_back_as_they_went_in(): void
    {
        $this->state()->cacheNetworks([self::ROW]);

        $this->assertSame([self::ROW], $this->state()->cachedNetworks());
    }

    #[Test]
    public function a_cache_that_is_missing_or_not_a_list_of_networks_is_no_cache(): void
    {
        $this->assertNull($this->state()->cachedNetworks());

        foreach (['not json', '{"ssid":"Home"}', '[{"band":"5"}]', '["Home"]'] as $contents) {
            file_put_contents($this->runtimeDir . '/provision-networks.json', $contents);
            $this->assertNull($this->state()->cachedNetworks(), $contents);
        }
    }

    #[Test]
    public function an_attempt_is_running_from_its_begin_to_its_end(): void
    {
        $state = $this->state();
        $this->assertFalse($state->isJoining());

        $state->beginAttempt();
        $this->assertTrue($state->isJoining());

        $state->endAttempt('Home', false, 'wrong_passphrase', 'The passphrase for "Home" was not accepted.');
        $this->assertFalse($state->isJoining());
        $this->assertSame(
            [
                'at' => 1_000,
                'ssid' => 'Home',
                'ok' => false,
                'reason' => 'wrong_passphrase',
                'message' => 'The passphrase for "Home" was not accepted.',
            ],
            $state->lastAttempt(),
        );
        $this->assertNull($state->joinedSsid());
    }

    /** A web server killed in the middle of a join must not keep the hotspot down for the rest of the run. */
    #[Test]
    public function an_attempt_that_never_ended_stops_counting_as_running_after_two_minutes(): void
    {
        $state = $this->state();
        $state->beginAttempt();

        $this->clock->sleep(119);
        $this->assertTrue($state->isJoining());

        $this->clock->sleep(1);
        $this->assertFalse($state->isJoining());
    }

    #[Test]
    public function a_successful_attempt_names_the_joined_network_until_the_next_reset(): void
    {
        $state = $this->state();
        $state->endAttempt('Home', true, null, null);
        $this->assertSame('Home', $state->joinedSsid());

        $state->reset();
        $this->assertNull($state->joinedSsid());
        $this->assertNull($state->lastAttempt());
    }

    /** Anyone who can write to the directory could otherwise declare the device provisioned, or never joining. */
    #[Test]
    public function nothing_is_read_from_a_directory_others_can_write_to(): void
    {
        $state = $this->state();
        $state->endAttempt('Home', true, null, null);
        $state->cacheNetworks([self::ROW]);
        $state->beginAttempt();

        chmod($this->runtimeDir, 0777);

        $this->assertNull($state->joinedSsid());
        $this->assertNull($state->cachedNetworks());
        $this->assertFalse($state->isJoining());
    }
}
