<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Tests\Support;

use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;

final class FakeTransportRecorder
{
    /** @var list<array{url: string, envelope: array<string, mixed>, payload: array<string, mixed>}> */
    public array $requests = [];

    private readonly \Closure $responder;

    public function __construct(
        private readonly string $sharedSecret = SaasTestContext::TEST_SECRET,
        ?callable $responder = null,
    ) {
        $this->responder = $responder !== null
            ? $responder(...)
            : static fn (array $payload): array => ['status' => 'provisioned', 'order_id' => $payload['order_id'] ?? 0];
    }

    public function asClosure(): \Closure
    {
        return function (string $url, array $rawEnvelope): array {
            $channel = new EncryptedChannel();
            $decrypted = $channel->open(EnvelopeDto::fromArray($rawEnvelope), $this->sharedSecret);
            $this->requests[] = [
                'url' => $url,
                'envelope' => $rawEnvelope,
                'payload' => $decrypted,
            ];

            $responsePayload = ($this->responder)($decrypted, $url);
            $appId = (string) ($rawEnvelope['app_id'] ?? 'cloudapp');

            return $channel->seal($appId, $responsePayload, $this->sharedSecret)->toArray();
        };
    }
}
