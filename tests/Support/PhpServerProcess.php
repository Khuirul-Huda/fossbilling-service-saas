<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Tests\Support;

final class PhpServerProcess
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $baseUrl = '';

    public function __destruct()
    {
        $this->stop();
    }

    public function start(string $dbPath, string $remoteAppUrl): string
    {
        $this->stop();
        $port = $this->findFreePort();
        $addr = '127.0.0.1:' . $port;
        $router = __DIR__ . '/e2e_php_router.php';

        $env = array_merge($_ENV, [
            'SERVICESAAS_E2E_DB' => $dbPath,
            'SERVICESAAS_E2E_REMOTE_URL' => $remoteAppUrl,
        ]);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-S', $addr, $router], $spec, $pipes, dirname($router), $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start PHP built-in E2E server.');
        }

        $this->process = $proc;
        $this->pipes = $pipes;
        $this->baseUrl = 'http://' . $addr;
        $this->waitUntilReady($port);

        return $this->baseUrl;
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
        throw new \RuntimeException('Timed out waiting for PHP built-in server on port ' . $port);
    }
}
