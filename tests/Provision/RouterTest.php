<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\Attributes\Test;

/**
 * `bin/wifi-portal.php` under PHP's real built-in web server, the way
 * `wifi provision` runs it, with the backend's commands replaced by fixtures.
 */
final class RouterTest extends ProvisionTestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, self::REPO_ROOT . '/bin/wifi-portal.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::REPO_ROOT,
            [
                'PATH' => (string) getenv('PATH'),
                'WIFI_FAKE_RUNNER' => self::REPO_ROOT . '/tests/Fixtures/cli/linux',
                'WIFI_FAKE_OS' => 'Linux',
                'WIFI_FAKE_LOG' => $this->runtimeDir . '/commands.log',
                'WIFI_RUNTIME_DIR' => $this->runtimeDir,
                'WIFI_PORTAL_NAME' => 'femus-pi.local',
            ],
        );
        self::assertIsResource($server);
        $this->server = $server;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('The built-in web server did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        parent::tearDown();
    }

    /**
     * A raw socket that stops reading at Content-Length, as a browser does:
     * PHP's own HTTP client reads until the server closes the connection,
     * which is exactly the moment this test must not wait for.
     *
     * @param array<string, string>|null $form
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function request(string $host, string $path, ?array $form = null): array
    {
        $socket = fsockopen('127.0.0.1', $this->port, $errno, $error, 5);
        self::assertIsResource($socket);
        stream_set_timeout($socket, 10);

        $content = $form === null ? '' : http_build_query($form);
        fwrite($socket, sprintf(
            "%s %s HTTP/1.1\r\nHost: %s\r\nContent-Type: application/x-www-form-urlencoded\r\n"
            . "Content-Length: %d\r\n\r\n%s",
            $form === null ? 'GET' : 'POST',
            $path,
            $host,
            strlen($content),
            $content,
        ));

        $headers = [];

        while (($line = fgets($socket)) !== false && rtrim($line) !== '') {
            $headers[] = rtrim($line);
        }

        $length = 0;

        foreach ($headers as $header) {
            if (stripos($header, 'Content-Length:') === 0) {
                $length = (int) trim(substr($header, 15));
            }
        }

        $body = '';

        while (strlen($body) < $length && ($chunk = fread($socket, $length - strlen($body))) !== false && $chunk !== '') {
            $body .= $chunk;
        }

        fclose($socket);

        self::assertMatchesRegularExpression('#^HTTP/\S+ \d{3}#', $headers[0] ?? '');
        self::assertSame($length, strlen($body), 'the body arrived whole');

        return ['status' => (int) substr($headers[0], 9, 3), 'headers' => $headers, 'body' => $body];
    }

    #[Test]
    public function a_phones_connectivity_check_is_redirected_to_the_setup_page(): void
    {
        $response = $this->request('captive.apple.com', '/hotspot-detect.html');

        $this->assertSame(302, $response['status']);
        $this->assertContains('Location: http://10.42.0.1/', $response['headers']);
    }

    #[Test]
    public function the_page_lists_the_networks(): void
    {
        $response = $this->request('10.42.0.1', '/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('value="BELL340"', $response['body']);
    }

    /**
     * The whole point of answering first: the phone has the complete answer
     * in hand while the join has not even begun, and the join then runs in
     * the same request, after the answer.
     */
    #[Test]
    public function the_answer_to_the_form_arrives_before_the_join_starts_and_the_join_follows(): void
    {
        $attempt = $this->runtimeDir . '/provision-attempt.json';

        $response = $this->request('10.42.0.1', '/', ['ssid' => 'BELL340', 'password' => 'p w']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('now joining <b>BELL340</b>', $response['body']);
        $this->assertStringContainsString('http://femus-pi.local/', $response['body']);
        $this->assertFileDoesNotExist($attempt, 'the join must not have finished before the answer arrived');

        for ($waited = 0; $waited < 100 && !is_file($attempt); $waited++) {
            usleep(100_000);
        }

        $this->assertSame('BELL340', $this->state()->joinedSsid());
        $this->assertStringContainsString('"stdin":"p w\\n"', (string) file_get_contents($this->runtimeDir . '/commands.log'));
    }
}
