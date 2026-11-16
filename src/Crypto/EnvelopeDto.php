<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Crypto;

final readonly class EnvelopeDto
{
    public const int SUPPORTED_VERSION = 1;
    public const int NONCE_BYTE_LENGTH = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    public int $v;
    public string $appId;
    public string $nonce;
    public string $payload;

    /**
     * @param int|string|array<string, mixed> $versionOrAppId
     */
    public function __construct(
        int|string|array $versionOrAppId = self::SUPPORTED_VERSION,
        string $appIdOrNonce = '',
        string $nonceOrPayload = '',
    ) {
        if (is_array($versionOrAppId)) {
            $validated = self::validateArray($versionOrAppId);
            $this->v = $validated['v'];
            $this->appId = $validated['app_id'];
            $this->nonce = $validated['nonce'];
            $this->payload = $validated['payload'];

            return;
        }

        if (is_string($versionOrAppId)) {
            $this->v = self::SUPPORTED_VERSION;
            $this->appId = $versionOrAppId;
            $this->nonce = $appIdOrNonce;
            $this->payload = $nonceOrPayload;

            return;
        }

        $extraArgs = func_get_args();
        $this->v = $versionOrAppId;
        $this->appId = $appIdOrNonce;
        $this->nonce = $nonceOrPayload;
        $this->payload = isset($extraArgs[3]) && is_string($extraArgs[3]) ? $extraArgs[3] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $validated = self::validateArray($data);

        return new self(
            $validated['v'],
            $validated['app_id'],
            $validated['nonce'],
            $validated['payload'],
        );
    }

    /**
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'app_id' => $this->appId,
            'nonce' => $this->nonce,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    private static function validateArray(array $data): array
    {
        $version = $data['v'] ?? null;
        if ($version !== self::SUPPORTED_VERSION) {
            throw new \InvalidArgumentException('Unsupported or missing envelope version.');
        }

        $appId = $data['app_id'] ?? null;
        if (!is_string($appId) || trim($appId) === '') {
            throw new \InvalidArgumentException('Envelope app_id must be a non-empty string.');
        }

        $nonce = $data['nonce'] ?? null;
        if (!is_string($nonce) || $nonce === '') {
            throw new \InvalidArgumentException('Envelope nonce must be a non-empty base64 string.');
        }

        $decodedNonce = base64_decode($nonce, true);
        if ($decodedNonce === false || strlen($decodedNonce) !== self::NONCE_BYTE_LENGTH) {
            throw new \InvalidArgumentException('Envelope nonce must decode to 24 bytes.');
        }

        $payload = $data['payload'] ?? null;
        if (!is_string($payload) || $payload === '') {
            throw new \InvalidArgumentException('Envelope payload must be a non-empty base64 string.');
        }

        $decodedPayload = base64_decode($payload, true);
        if ($decodedPayload === false || $decodedPayload === '') {
            throw new \InvalidArgumentException('Envelope payload must be valid non-empty base64.');
        }

        return [
            'v' => self::SUPPORTED_VERSION,
            'app_id' => $appId,
            'nonce' => $nonce,
            'payload' => $payload,
        ];
    }
}
