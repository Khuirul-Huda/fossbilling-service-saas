<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;

/**
 * Verifies short-lived encrypted SSO tokens for customer portal access.
 */
final class PortalSso
{
    public function __construct(
        private readonly EncryptedChannel $channel,
        private readonly AppRegistry $registry,
        private readonly ServiceSaasRepository $repository,
    ) {
    }

    /**
     * @return array{client_id: int, redirect_path: string}
     */
    public function resolvePortalTarget(string $encodedToken): array
    {
        $envelope = $this->decodeTokenEnvelope($encodedToken);
        $appConfig = $this->registry->get($envelope->appId);
        $decrypted = $this->channel->open($envelope, $appConfig->sharedSecret);

        $record = $this->findMatchingRecord($envelope->appId, $decrypted);
        if ($record === null || $record->clientId <= 0) {
            throw new \RuntimeException('No active SaaS billing record found for portal login.');
        }

        $redirectPath = $record->orderId !== null
            ? '/order/service/manage/' . $record->orderId
            : '/order/service';

        return [
            'client_id' => $record->clientId,
            'redirect_path' => $redirectPath,
        ];
    }

    private function decodeTokenEnvelope(string $encodedToken): EnvelopeDto
    {
        $trimmed = trim($encodedToken);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Portal SSO token is required.');
        }

        $padded = strtr($trimmed, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        $rawJson = base64_decode($padded, true);
        if (!is_string($rawJson) || $rawJson === '') {
            throw new \InvalidArgumentException('Portal SSO token is malformed.');
        }

        $decoded = json_decode($rawJson, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Portal SSO envelope must be a JSON object.');
        }

        return EnvelopeDto::fromArray($decoded);
    }

    /** @param array<string, mixed> $decrypted */
    private function findMatchingRecord(string $appId, array $decrypted): ?ServiceSaasRecord
    {
        $extUserId = trim((string) ($decrypted['external_user_id'] ?? $decrypted['externalUserId'] ?? ''));
        if ($extUserId !== '') {
            $byExt = $this->repository->findByExternalUserId($appId, $extUserId);
            if ($byExt !== null) {
                return $byExt;
            }
        }

        $email = strtolower(trim((string) ($decrypted['email'] ?? '')));
        if ($email !== '') {
            return $this->repository->findPendingOrActiveByEmail($appId, $email);
        }

        return null;
    }
}
