<?php

declare(strict_types=1);

use Box\Mod\Servicesaas\Crypto\EncryptedChannel;

it('generates unique 64-char hex libsodium shared secrets usable for XSalsa20-Poly1305 encryption', function (): void {
    $secretA = EncryptedChannel::generateHexSecret();
    $secretB = EncryptedChannel::generateHexSecret();

    expect($secretA)->toHaveLength(64)
        ->and(ctype_xdigit($secretA))->toBeTrue()
        ->and($secretB)->toHaveLength(64)
        ->and($secretA)->not->toBe($secretB);

    $channel = new EncryptedChannel();
    $envelope = $channel->seal('sebarke', ['action' => 'ping', 'value' => 42], $secretA);
    $opened = $channel->open($envelope, $secretA);

    expect($opened['action'])->toBe('ping')
        ->and($opened['value'])->toBe(42);
});
