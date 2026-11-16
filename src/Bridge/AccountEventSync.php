<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;

final class AccountEventSync
{
    public const string EVENT_EMAIL_CHANGED = 'email_changed';
    public const string EVENT_ACCOUNT_DELETED = 'account_deleted';

    public function __construct(
        private readonly EncryptedChannel $channel,
        private readonly AppRegistry $registry,
        private readonly ServiceSaasRepository $repository,
    ) {
    }

    /**
     * @param array<string, mixed> $rawEnvelope
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function handleEncryptedEvent(array $rawEnvelope, ?\Closure $onEmailChanged = null): array
    {
        $envelope = EnvelopeDto::fromArray($rawEnvelope);
        $appConfig = $this->registry->get($envelope->appId);
        $decrypted = $this->channel->open($envelope, $appConfig->sharedSecret);

        $appId = $this->resolveAppId($envelope, $decrypted);
        $event = strtolower(trim((string) ($decrypted['event'] ?? $decrypted['event_type'] ?? '')));
        $record = $this->locateRecord($appId, $decrypted);

        if ($event === self::EVENT_EMAIL_CHANGED) {
            $this->processEmailChanged($record, $decrypted, $onEmailChanged);
        } elseif ($event === self::EVENT_ACCOUNT_DELETED) {
            $this->processAccountDeleted($record);
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported SaaS account event "%s".', $event));
        }

        return $this->channel->seal($appId, ['status' => 'ok'], $appConfig->sharedSecret)->toArray();
    }

    /**
     * @param array<string, mixed> $decrypted
     */
    private function resolveAppId(EnvelopeDto $envelope, array $decrypted): string
    {
        $payloadAppId = trim((string) ($decrypted['app_id'] ?? $decrypted['appId'] ?? $envelope->appId));
        if ($payloadAppId !== $envelope->appId) {
            throw new \InvalidArgumentException('Envelope app_id does not match account event app_id.');
        }

        return $envelope->appId;
    }

    /**
     * @param array<string, mixed> $decrypted
     */
    private function locateRecord(string $appId, array $decrypted): ?ServiceSaasRecord
    {
        $externalUserId = trim((string) ($decrypted['external_user_id'] ?? $decrypted['externalUserId'] ?? ''));
        if ($externalUserId !== '') {
            $byExternal = $this->repository->findByExternalUserId($appId, $externalUserId);
            if ($byExternal !== null) {
                return $byExternal;
            }
        }

        $lookupEmail = strtolower(trim((string) ($decrypted['old_email'] ?? $decrypted['email'] ?? '')));
        if ($lookupEmail === '') {
            return null;
        }

        return $this->repository->findPendingOrActiveByEmail($appId, $lookupEmail);
    }

    /**
     * @param array<string, mixed> $decrypted
     */
    private function processEmailChanged(
        ?ServiceSaasRecord $record,
        array $decrypted,
        ?\Closure $onEmailChanged,
    ): void {
        $newEmail = strtolower(trim((string) ($decrypted['new_email'] ?? $decrypted['email'] ?? '')));
        if ($newEmail === '' || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Account email_changed event requires a valid new_email.');
        }

        if ($record !== null) {
            $record->targetEmail = $newEmail;
            $record->lastSyncAt = gmdate('Y-m-d H:i:s');
            $record = $this->repository->update($record);
        }

        if ($onEmailChanged !== null) {
            $onEmailChanged($record, $newEmail, $decrypted);
        }
    }

    private function processAccountDeleted(?ServiceSaasRecord $record): void
    {
        if ($record === null) {
            return;
        }

        $record->provisioningStatus = ServiceSaasRecord::STATUS_CANCELED;
        $record->lastSyncAt = gmdate('Y-m-d H:i:s');
        $this->repository->update($record);
    }
}
