<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Tests\Support;

final class RemoteAppServerProcess
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $baseUrl = '';
    private ?string $dbPath = null;

    public function __destruct()
    {
        $this->stop();
    }

    public function start(string $fossbillingBaseUrl = '', string $appId = 'cloudapp', string $secret = SaasTestContext::TEST_SECRET): string
    {
        $this->stop();
        $this->dbPath = sys_get_temp_dir() . '/servicesaas_remote_' . bin2hex(random_bytes(5)) . '.sqlite';
        $port = $this->findFreePort();
        $addr = '127.0.0.1:' . $port;
        $router = __DIR__ . '/remote_app_router.php';

        $env = array_merge($_ENV, [
            'SERVICESAAS_REMOTE_DB' => $this->dbPath,
            'SERVICESAAS_REMOTE_APP_ID' => $appId,
            'SERVICESAAS_REMOTE_SECRET' => $secret,
            'SERVICESAAS_REMOTE_FB_URL' => $fossbillingBaseUrl,
        ]);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-S', $addr, $router], $spec, $pipes, dirname($router), $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start remote SaaS test server.');
        }

        $this->process = $proc;
        $this->pipes = $pipes;
        $this->baseUrl = 'http://' . $addr;
        $this->waitUntilReady($port);

        return $this->baseUrl;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body): array
    {
        return $this->requestJson('POST', $this->baseUrl . $path, $body);
    }

    /** @return array<string, mixed> */
    public function getSubscription(int $userId): array
    {
        return $this->requestJson('GET', $this->baseUrl . '/_test/subscription/' . $userId);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        $this->baseUrl = '';

        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        $this->dbPath = null;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, ?array $body = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Failed to init cURL for remote app test server.');
        }

        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($handle, $opts);
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            throw new \RuntimeException('Remote test server request failed: ' . curl_error($handle));
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Unable to allocate free TCP port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $parts = explode(':', $name);

        return (int) end($parts);
    }

    private function waitUntilReady(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            set_error_handler(static fn (): bool => true);
            $conn = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
            restore_error_handler();
            if (is_resource($conn)) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }

        $this->stop();
        throw new \RuntimeException('Timed out waiting for remote test server on port ' . $port);
    }
}
