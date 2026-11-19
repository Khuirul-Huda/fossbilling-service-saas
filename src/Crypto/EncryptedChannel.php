<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Crypto;

final class EncryptedChannel
{
    public const int SECRET_KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    public const int HEX_SECRET_KEY_LENGTH = 64;
    public const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    public const int REQUEST_ID_RANDOM_BYTES = 16;
    private const int JSON_DECODE_DEPTH = 512;

    private readonly ReplayGuard $replayGuard;

    public function __construct(?ReplayGuard $replayGuard = null)
    {
        $this->replayGuard = $replayGuard ?? new ReplayGuard();
    }

    public static function persistent(): self
    {
        return new self(ReplayGuard::persistent());
    }

    public static function normalizeSecretKey(string $secret): string
    {
        if (strlen($secret) === self::SECRET_KEY_BYTES) {
            return $secret;
        }

        if (strlen($secret) === self::HEX_SECRET_KEY_LENGTH && ctype_xdigit($secret)) {
            $decodedHex = hex2bin($secret);
            if ($decodedHex !== false && strlen($decodedHex) === self::SECRET_KEY_BYTES) {
                return $decodedHex;
            }
        }

        $decodedBase64 = base64_decode($secret, true);
        if ($decodedBase64 !== false && strlen($decodedBase64) === self::SECRET_KEY_BYTES) {
            return $decodedBase64;
        }

        throw new \InvalidArgumentException('Shared secret must resolve to a 32-byte key (raw, 64-char hex, or base64).');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function seal(string $appId, array $payload, string $sharedSecret): EnvelopeDto
    {
        if (trim($appId) === '') {
            throw new \InvalidArgumentException('App ID must not be empty when sealing an envelope.');
        }

        $key = self::normalizeSecretKey($sharedSecret);
        $enrichedPayload = $this->withReplayMetadata($payload);

        try {
            $plaintext = json_encode($enrichedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Failed to encode payload as JSON: ' . $exception->getMessage(), 0, $exception);
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return EnvelopeDto::fromArray([
            'v' => EnvelopeDto::SUPPORTED_VERSION,
            'app_id' => $appId,
            'nonce' => base64_encode($nonce),
            'payload' => base64_encode($ciphertext),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function open(EnvelopeDto $envelope, string $sharedSecret, ?int $now = null): array
    {
        $key = self::normalizeSecretKey($sharedSecret);
        $nonce = base64_decode($envelope->nonce, true);
        $ciphertext = base64_decode($envelope->payload, true);

        if ($nonce === false || strlen($nonce) !== self::NONCE_BYTES) {
            throw new \RuntimeException('Invalid envelope nonce.');
        }

        if ($ciphertext === false || $ciphertext === '') {
            throw new \RuntimeException('Invalid envelope ciphertext.');
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt envelope payload.');
        }

        $decoded = $this->decodeJsonPayload($plaintext);
        $this->verifyReplayMetadata($decoded, $now);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withReplayMetadata(array $payload): array
    {
        if (!isset($payload['timestamp'])) {
            $payload['timestamp'] = time();
        }

        if (!isset($payload['request_id']) || !is_string($payload['request_id']) || trim($payload['request_id']) === '') {
            $payload['request_id'] = bin2hex(random_bytes(self::REQUEST_ID_RANDOM_BYTES));
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $plaintext): array
    {
        try {
            $decoded = json_decode($plaintext, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Decrypted payload is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Decrypted JSON payload must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function verifyReplayMetadata(array $decoded, ?int $now): void
    {
        $requestId = isset($decoded['request_id']) && is_string($decoded['request_id']) ? $decoded['request_id'] : '';
        $rawTimestamp = $decoded['timestamp'] ?? null;

        if (!is_int($rawTimestamp) && !is_numeric($rawTimestamp)) {
            throw new \RuntimeException('Decrypted payload is missing a valid timestamp.');
        }

        $this->replayGuard->assertFreshAndUnique($requestId, (int) $rawTimestamp, $now);
    }
}
