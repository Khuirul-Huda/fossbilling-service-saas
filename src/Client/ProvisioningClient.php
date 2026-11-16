<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Client;

use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;

final class ProvisioningClient
{
    private const int DEFAULT_TIMEOUT_SECONDS = 15;
    private const int HTTP_SUCCESS_MIN = 200;
    private const int HTTP_SUCCESS_MAX = 299;
    private const int JSON_DECODE_DEPTH = 512;

    private readonly \Closure $httpTransport;

    public function __construct(
        private readonly EncryptedChannel $channel,
        private readonly AppRegistry $registry,
        ?\Closure $httpTransport = null,
    ) {
        $this->httpTransport = $httpTransport ?? $this->sendCurlRequest(...);
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatch(ProvisioningPayload $payload): array
    {
        $appConfig = $this->registry->get($payload->appId);
        $wirePayload = $payload->toArray();
        if ($wirePayload['plan_metadata'] === []) {
            $wirePayload['plan_metadata'] = new \stdClass();
        }
        $envelope = $this->channel->seal($payload->appId, $wirePayload, $appConfig->sharedSecret);

        $rawResponse = ($this->httpTransport)($appConfig->getWebhookUrl(), $envelope->toArray());
        $responseEnvelope = EnvelopeDto::fromArray($rawResponse);

        if ($responseEnvelope->appId !== $payload->appId) {
            throw new \RuntimeException('Provisioning response app_id mismatch.');
        }

        $decrypted = $this->channel->open($responseEnvelope, $appConfig->sharedSecret);
        if (($decrypted['status'] ?? 'ok') === 'error') {
            $message = (string) ($decrypted['error'] ?? $decrypted['message'] ?? 'Remote SaaS provisioning error.');
            throw new \RuntimeException($message);
        }

        return $decrypted;
    }

    /**
     * @param array<string, mixed> $envelopeArray
     *
     * @return array<string, mixed>
     */
    private function sendCurlRequest(string $url, array $envelopeArray): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Failed to initialize cURL handle for SaaS provisioning.');
        }

        $body = json_encode($envelopeArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $responseBody = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);

        if (!is_string($responseBody)) {
            throw new \RuntimeException('SaaS provisioning HTTP request failed: ' . $curlError);
        }

        if ($httpCode < self::HTTP_SUCCESS_MIN || $httpCode > self::HTTP_SUCCESS_MAX) {
            throw new \RuntimeException(sprintf('SaaS provisioning endpoint returned HTTP %d.', $httpCode));
        }

        try {
            $decoded = json_decode($responseBody, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Invalid JSON from SaaS provisioning endpoint: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('SaaS provisioning endpoint must return a JSON object.');
        }

        return $decoded;
    }
}
