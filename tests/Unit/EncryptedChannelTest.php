<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Crypto\ReplayGuard;

function unitTestRawKey(): string
{
    return random_bytes(EncryptedChannel::SECRET_KEY_BYTES);
}

it('round-trips sealed payloads with raw 32-byte, 64-char hex, and base64 keys', function (): void {
    // Arrange
    $rawKey = unitTestRawKey();
    $formats = [
        'raw' => $rawKey,
        'hex' => bin2hex($rawKey),
        'base64' => base64_encode($rawKey),
    ];
    $payload = ['action' => 'activate', 'order_id' => 42, 'email' => 'alice@example.com'];

    foreach ($formats as $label => $secret) {
        $channel = new EncryptedChannel(new ReplayGuard());

        // Act
        $envelope = $channel->seal('cloudapp', $payload, $secret);
        $opened = $channel->open($envelope, $rawKey);

        // Assert
        expect($envelope->v)->toBe(EnvelopeDto::SUPPORTED_VERSION)
            ->and($envelope->appId)->toBe('cloudapp')
            ->and($opened['action'])->toBe('activate')
            ->and($opened['order_id'])->toBe(42)
            ->and($opened['email'])->toBe('alice@example.com')
            ->and($opened['request_id'])->toBeString()->not->toBeEmpty($label);
    }
});

it('rejects ciphertext when a single bit is flipped (Poly1305 MAC failure)', function (): void {
    // Arrange
    $secret = bin2hex(unitTestRawKey());
    $channel = new EncryptedChannel();
    $envelope = $channel->seal('cloudapp', ['order_id' => 99], $secret);

    $cipherBytes = base64_decode($envelope->payload, true);
    expect($cipherBytes)->toBeString();
    $tamperedBytes = $cipherBytes;
    $tamperedBytes[0] = chr(ord($tamperedBytes[0]) ^ 0x01);

    $tampered = EnvelopeDto::fromArray([
        'v' => $envelope->v,
        'app_id' => $envelope->appId,
        'nonce' => $envelope->nonce,
        'payload' => base64_encode($tamperedBytes),
    ]);

    // Act & Assert
    expect(fn (): array => $channel->open($tampered, $secret))
        ->toThrow(\RuntimeException::class, 'Failed to decrypt envelope payload.');
});

it('rejects wrong secret key lengths during normalization and sealing', function (string $badSecret): void {
    // Arrange
    $channel = new EncryptedChannel();

    // Act & Assert
    expect(fn (): string => EncryptedChannel::normalizeSecretKey($badSecret))
        ->toThrow(\InvalidArgumentException::class);
    expect(fn (): EnvelopeDto => $channel->seal('cloudapp', ['ok' => true], $badSecret))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'empty key' => [''],
    '16-byte raw key' => [str_repeat('a', 16)],
    '63-char hex key' => [str_repeat('a', 63)],
    'base64 of 16 bytes' => [base64_encode(str_repeat('b', 16))],
]);

it('rejects envelopes with invalid nonce lengths', function (string $badNonce): void {
    // Act & Assert
    expect(fn (): EnvelopeDto => EnvelopeDto::fromArray([
        'v' => 1,
        'app_id' => 'cloudapp',
        'nonce' => $badNonce,
        'payload' => base64_encode('ciphertext'),
    ]))->toThrow(\InvalidArgumentException::class);

    $channel = new EncryptedChannel();
    $rawDto = new EnvelopeDto(1, 'cloudapp', $badNonce);
    expect(fn (): array => $channel->open($rawDto, bin2hex(unitTestRawKey())))
        ->toThrow(\RuntimeException::class);
})->with([
    'empty nonce' => [''],
    'non-base64 nonce' => ['%%%not-base64%%%'],
    '12-byte AES-GCM style nonce' => [base64_encode(str_repeat('n', 12))],
    '25-byte oversized nonce' => [base64_encode(str_repeat('n', 25))],
]);

it('rejects timestamps drifting more than 300 seconds in the past or future while accepting the 300s boundary', function (): void {
    // Arrange
    $secret = bin2hex(unitTestRawKey());
    $now = 1_750_000_000;
    $channel = new EncryptedChannel(new ReplayGuard(300, 600));

    $staleEnvelope = $channel->seal('cloudapp', ['timestamp' => $now - 301, 'request_id' => 'req-past-301'], $secret);
    $futureEnvelope = $channel->seal('cloudapp', ['timestamp' => $now + 301, 'request_id' => 'req-future-301'], $secret);
    $pastBoundary = $channel->seal('cloudapp', ['timestamp' => $now - 300, 'request_id' => 'req-past-300'], $secret);
    $futureBoundary = $channel->seal('cloudapp', ['timestamp' => $now + 300, 'request_id' => 'req-future-300'], $secret);

    // Act & Assert
    expect(fn (): array => $channel->open($staleEnvelope, $secret, $now))
        ->toThrow(\RuntimeException::class, 'clock drift');
    expect(fn (): array => $channel->open($futureEnvelope, $secret, $now))
        ->toThrow(\RuntimeException::class, 'clock drift');
    expect($channel->open($pastBoundary, $secret, $now)['request_id'])->toBe('req-past-300');
    expect($channel->open($futureBoundary, $secret, $now)['request_id'])->toBe('req-future-300');
});

it('rejects duplicate request_id within the replay window and allows after TTL purge', function (): void {
    // Arrange
    $secret = bin2hex(unitTestRawKey());
    $now = 1_750_000_000;
    $channel = new EncryptedChannel(new ReplayGuard(300, 600));
    $first = $channel->seal('cloudapp', ['timestamp' => $now, 'request_id' => 'dup-req-1'], $secret);
    $duplicate = $channel->seal('cloudapp', ['timestamp' => $now + 10, 'request_id' => 'dup-req-1'], $secret);

    // Act
    $opened = $channel->open($first, $secret, $now);

    // Assert
    expect($opened['request_id'])->toBe('dup-req-1');
    expect(fn (): array => $channel->open($duplicate, $secret, $now + 10))
        ->toThrow(\RuntimeException::class, 'Duplicate request_id');

    $afterTtl = $now + 601;
    $reusedAfterExpiry = $channel->seal('cloudapp', ['timestamp' => $afterTtl, 'request_id' => 'dup-req-1'], $secret);
    expect($channel->open($reusedAfterExpiry, $secret, $afterTtl)['request_id'])->toBe('dup-req-1');
});
