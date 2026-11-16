<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Crypto;

final class ReplayGuard
{
    public const int DEFAULT_MAX_DRIFT_SECONDS = 300;
    public const int DEFAULT_TTL_SECONDS = 600;

    /** @var array<string, int> */
    private array $seenRequests = [];

    public function __construct(
        private readonly int $maxDriftSeconds = self::DEFAULT_MAX_DRIFT_SECONDS,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    public function assertFreshAndUnique(string $requestId, int $timestamp, ?int $now = null): void
    {
        if (trim($requestId) === '') {
            throw new \RuntimeException('Replay protection requires a non-empty request_id.');
        }

        $currentTime = $now ?? time();
        $this->purgeExpired($currentTime);

        if (abs($currentTime - $timestamp) > $this->maxDriftSeconds) {
            throw new \RuntimeException('Envelope timestamp is outside the allowed clock drift window.');
        }

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
}
