<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Client\ExpirationCalculator;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;

final class EntitlementClaimer
{
    private const int MICROSECONDS_THRESHOLD = 1_000_000_000_000;

    private ?\Pimple\Container $di = null;

    public function __construct(
        private readonly EncryptedChannel $channel,
        private readonly AppRegistry $registry,
        private readonly ServiceSaasRepository $repository,
    ) {
    }

    public function setDi(\Pimple\Container $di): self
    {
        $this->di = $di;

        return $this;
    }

    /**
     * @param array<string, mixed> $rawEnvelope
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function handleEncryptedClaim(array $rawEnvelope, ?\Closure $orderExpiryResetter = null): array
    {
        $envelope = EnvelopeDto::fromArray($rawEnvelope);
        $appConfig = $this->registry->get($envelope->appId);
        $decrypted = $this->channel->open($envelope, $appConfig->sharedSecret);

        $appId = $this->resolveAppId($envelope, $decrypted);
        $email = $this->extractValidEmail($decrypted);
        $externalUserId = trim((string) ($decrypted['external_user_id'] ?? $decrypted['externalUserId'] ?? ''));

        $record = $this->repository->findPendingOrActiveByEmail($appId, $email);
        if (!$this->isClaimable($record)) {
            return $this->channel->seal($appId, ['matched' => false], $appConfig->sharedSecret)->toArray();
        }

        if ($externalUserId !== '') {
            $record->externalUserId = $externalUserId;
        }
        $record->provisioningStatus = ServiceSaasRecord::STATUS_PROVISIONED;
        $record->lastSyncAt = gmdate('Y-m-d H:i:s');
        $updated = $this->repository->update($record);

        $expiresAtMicro = $this->resetOrderExpiry($updated, $orderExpiryResetter);

        return $this->channel->seal($appId, [
            'matched' => true,
            'order_id' => $updated->orderId,
            'client_id' => $updated->clientId,
            'plan_id' => $updated->planId,
            'plan_metadata' => $updated->planMetadata === [] ? new \stdClass() : $updated->planMetadata,
            'expires_at_micro' => $expiresAtMicro,
        ], $appConfig->sharedSecret)->toArray();
    }

    /**
     * @param array<string, mixed> $decrypted
     */
    private function resolveAppId(EnvelopeDto $envelope, array $decrypted): string
    {
        $payloadAppId = trim((string) ($decrypted['app_id'] ?? $decrypted['appId'] ?? $envelope->appId));
        if ($payloadAppId !== $envelope->appId) {
            throw new \InvalidArgumentException('Envelope app_id does not match claim payload app_id.');
        }

        return $envelope->appId;
    }

    /**
     * @param array<string, mixed> $decrypted
     */
    private function extractValidEmail(array $decrypted): string
    {
        $email = strtolower(trim((string) ($decrypted['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Entitlement claim requires a valid email address.');
        }

        return $email;
    }

    private function isClaimable(?ServiceSaasRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        return !in_array(
            $record->provisioningStatus,
            [ServiceSaasRecord::STATUS_CANCELED, ServiceSaasRecord::STATUS_SUSPENDED],
            true,
        );
    }

    private function resetOrderExpiry(ServiceSaasRecord $record, ?\Closure $orderExpiryResetter): int
    {
        if ($orderExpiryResetter !== null) {
            $customResult = $orderExpiryResetter($record);

            return $this->normalizeResetterResult($record, $customResult);
        }

        $calculator = new ExpirationCalculator();
        $periodCode = $this->resolvePeriodCode($record);

        if ($this->di !== null && $record->orderId !== null) {
            return $this->resetDiOrderExpiry($this->di, $record->orderId, $periodCode, $calculator);
        }

        return ExpirationCalculator::toMicroseconds($calculator->calculateForActivation($periodCode));
    }

    private function normalizeResetterResult(ServiceSaasRecord $record, mixed $customResult): int
    {
        if ($customResult instanceof \DateTimeInterface) {
            return ExpirationCalculator::toMicroseconds($customResult->getTimestamp());
        }

        if (is_int($customResult) && $customResult > self::MICROSECONDS_THRESHOLD) {
            return $customResult;
        }

        if (is_int($customResult) && $customResult > 0) {
            return ExpirationCalculator::toMicroseconds($customResult);
        }

        $calculator = new ExpirationCalculator();

        return ExpirationCalculator::toMicroseconds(
            $calculator->calculateForActivation($this->resolvePeriodCode($record)),
        );
    }

    private function resolvePeriodCode(ServiceSaasRecord $record): ?string
    {
        $period = $record->planMetadata['period'] ?? null;

        return is_string($period) && trim($period) !== '' ? $period : null;
    }

    private function resetDiOrderExpiry(
        \Pimple\Container $di,
        int $orderId,
        ?string $fallbackPeriod,
        ExpirationCalculator $calculator,
    ): int {
        $order = $di['em']->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            return ExpirationCalculator::toMicroseconds($calculator->calculateForActivation($fallbackPeriod));
        }

        $periodCode = $order->getPeriod() ?? $fallbackPeriod;
        $expiresAtSeconds = $calculator->calculateForActivation($periodCode);
        $order->setExpiresAt((new \DateTime())->setTimestamp($expiresAtSeconds));
        $order->setUpdatedAt(new \DateTime());
        $di['em']->persist($order);
        $di['em']->flush();

        return ExpirationCalculator::toMicroseconds($expiresAtSeconds);
    }
}
