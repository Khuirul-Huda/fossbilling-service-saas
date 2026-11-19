<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Crypto;

final class ReplayGuard
{
    public const int DEFAULT_MAX_DRIFT_SECONDS = 300;
    public const int DEFAULT_TTL_SECONDS = 600;
    private const int CLEANUP_PROBABILITY_MOD = 50;

    /** @var array<string, int> */
    private array $seenRequests = [];

    public function __construct(
        private readonly int $maxDriftSeconds = self::DEFAULT_MAX_DRIFT_SECONDS,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly ?string $storageDir = null,
    ) {
    }

    public static function persistent(
        int $maxDriftSeconds = self::DEFAULT_MAX_DRIFT_SECONDS,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): self {
        return new self($maxDriftSeconds, $ttlSeconds, sys_get_temp_dir() . '/fossbilling_servicesaas_replay');
    }

    public function assertFreshAndUnique(string $requestId, int $timestamp, ?int $now = null): void
    {
        if (trim($requestId) === '') {
            throw new \RuntimeException('Replay protection requires a non-empty request_id.');
        }

        $currentTime = $now ?? time();
        if (abs($currentTime - $timestamp) > $this->maxDriftSeconds) {
            throw new \RuntimeException('Envelope timestamp is outside the allowed clock drift window.');
        }

        if ($this->storageDir !== null) {
            $this->assertAndPersistMarker($requestId, $currentTime);

            return;
        }

        $this->purgeExpired($currentTime);
        if (isset($this->seenRequests[$requestId])) {
            throw new \RuntimeException('Duplicate request_id detected within replay window.');
        }

        $this->seenRequests[$requestId] = $currentTime + $this->ttlSeconds;
    }

    public function purgeExpired(int $now): void
    {
        foreach ($this->seenRequests as $requestId => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->seenRequests[$requestId]);
            }
        }
    }

    private function assertAndPersistMarker(string $requestId, int $currentTime): void
    {
        $dir = (string) $this->storageDir;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to initialize replay guard directory.');
        }

        $markerPath = $dir . '/' . hash('sha256', $requestId) . '.lock';
        $handle = fopen($markerPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open replay guard marker.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock replay guard marker.');
            }
            $rawExpiry = stream_get_contents($handle);
            $expiresAt = is_string($rawExpiry) && is_numeric(trim($rawExpiry)) ? (int) trim($rawExpiry) : 0;
            if ($expiresAt > $currentTime) {
                throw new \RuntimeException('Duplicate request_id detected within replay window.');
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($currentTime + $this->ttlSeconds));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (random_int(1, self::CLEANUP_PROBABILITY_MOD) === 1) {
            $this->purgeExpiredFiles($dir, $currentTime);
        }
    }

    private function purgeExpiredFiles(string $dir, int $currentTime): void
    {
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.lock')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            $raw = @file_get_contents($path);
            if (is_string($raw) && is_numeric(trim($raw)) && (int) trim($raw) <= $currentTime) {
                @unlink($path);
            }
        }
    }
}
