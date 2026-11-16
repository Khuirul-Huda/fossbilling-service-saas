<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Persistence;

final class ServiceSaasRecord
{
    public const string STATUS_PENDING_CLAIM = 'pending_claim';
    public const string STATUS_PROVISIONED = 'provisioned';
    public const string STATUS_SUSPENDED = 'suspended';
    public const string STATUS_CANCELED = 'canceled';
    private const int JSON_DECODE_DEPTH = 512;
    private const int BYTES_PER_GIB = 1_073_741_824;

    public ?int $id = null;
    public int $clientId = 0;
    public ?int $orderId = null;
    public string $appId = '';
    public string $planId = '';
    public string $targetEmail = '';
    public ?string $externalUserId = null;
    public string $provisioningStatus = self::STATUS_PENDING_CLAIM;

    /** @var array<string, mixed> */
    public array $planMetadata = [];

    public ?string $returnRedirectUrl = null;
    public ?string $lastSyncAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    /**
     * @param int|array<string, mixed>|null $idOrData
     */
    public function __construct(
        int|array|null $idOrData = null,
        int $clientId = 0,
        ?int $orderId = null,
    ) {
        if (is_array($idOrData)) {
            $this->hydrateFromArray($idOrData);

            return;
        }

        if (func_num_args() === 0) {
            return;
        }

        $this->hydrateFromPositionalArgs(func_get_args(), $clientId, $orderId);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientId(): int
    {
        return $this->clientId;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function getPlanId(): string
    {
        return $this->planId;
    }

    public function getTargetEmail(): string
    {
        return $this->targetEmail;
    }

    public function getExternalUserId(): ?string
    {
        return $this->externalUserId;
    }

    public function getProvisioningStatus(): string
    {
        return $this->provisioningStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanMetadata(): array
    {
        return $this->planMetadata;
    }

    public function getReturnRedirectUrl(): ?string
    {
        return $this->returnRedirectUrl;
    }

    public function getLastSyncAt(): ?string
    {
        return $this->lastSyncAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->clientId,
            'order_id' => $this->orderId,
            'app_id' => $this->appId,
            'plan_id' => $this->planId,
            'target_email' => $this->targetEmail,
            'external_user_id' => $this->externalUserId,
            'provisioning_status' => $this->provisioningStatus,
            'plan_metadata' => $this->planMetadata,
            'return_redirect_url' => $this->returnRedirectUrl,
            'last_sync_at' => $this->lastSyncAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateFromArray(array $data): void
    {
        $rawId = $data['id'] ?? null;
        $this->id = $rawId !== null && $rawId !== '' ? (int) $rawId : null;
        $this->clientId = (int) ($data['client_id'] ?? $data['clientId'] ?? 0);

        $rawOrderId = $data['order_id'] ?? $data['orderId'] ?? null;
        $this->orderId = $rawOrderId !== null && $rawOrderId !== '' ? (int) $rawOrderId : null;

        $this->appId = (string) ($data['app_id'] ?? $data['appId'] ?? '');
        $this->planId = (string) ($data['plan_id'] ?? $data['planId'] ?? '');
        $this->targetEmail = strtolower(trim((string) ($data['target_email'] ?? $data['targetEmail'] ?? '')));

        $extUser = $data['external_user_id'] ?? $data['externalUserId'] ?? null;
        $this->externalUserId = is_string($extUser) && $extUser !== '' ? $extUser : null;

        $status = (string) ($data['provisioning_status'] ?? $data['provisioningStatus'] ?? self::STATUS_PENDING_CLAIM);
        $this->provisioningStatus = $status !== '' ? $status : self::STATUS_PENDING_CLAIM;

        $this->planMetadata = self::parseMetadata($data['plan_metadata'] ?? $data['planMetadata'] ?? []);
        $this->returnRedirectUrl = self::nullableString($data['return_redirect_url'] ?? $data['returnRedirectUrl'] ?? null);
        $this->lastSyncAt = self::nullableString($data['last_sync_at'] ?? $data['lastSyncAt'] ?? null);
        $this->createdAt = self::nullableString($data['created_at'] ?? $data['createdAt'] ?? null);
        $this->updatedAt = self::nullableString($data['updated_at'] ?? $data['updatedAt'] ?? null);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function hydrateFromPositionalArgs(array $args, int $clientId, ?int $orderId): void
    {
        $this->id = isset($args[0]) && is_int($args[0]) ? $args[0] : null;
        $this->clientId = $clientId;
        $this->orderId = $orderId;
        $this->appId = isset($args[3]) ? (string) $args[3] : '';
        $this->planId = isset($args[4]) ? (string) $args[4] : '';
        $this->targetEmail = isset($args[5]) ? strtolower(trim((string) $args[5])) : '';
        $this->externalUserId = self::nullableString($args[6] ?? null);
        $this->provisioningStatus = isset($args[7]) && is_string($args[7]) && $args[7] !== ''
            ? $args[7]
            : self::STATUS_PENDING_CLAIM;
        $this->planMetadata = self::parseMetadata($args[8] ?? []);
        $this->returnRedirectUrl = self::nullableString($args[9] ?? null);
        $this->lastSyncAt = self::nullableString($args[10] ?? null);
        $this->createdAt = self::nullableString($args[11] ?? null);
        $this->updatedAt = self::nullableString($args[12] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function mergePlanMetadataFromConfig(array $config): array
    {
        $base = self::parseMetadata($config['plan_metadata'] ?? []);
        $extra = self::parseMetadata($config['plan_metadata_json'] ?? []);
        $merged = array_merge($base, $extra);

        $bytes = (int) ($merged['storage_bytes'] ?? 0);
        $gb = $merged['storage_gb'] ?? null;
        if ($bytes <= 0 && is_numeric($gb) && (float) $gb > 0) {
            $merged['storage_bytes'] = (int) round((float) $gb * self::BYTES_PER_GIB);
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $str = (string) $value;

        return $str !== '' ? $str : null;
    }
}
