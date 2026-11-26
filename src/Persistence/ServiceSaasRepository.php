<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Persistence;

use Doctrine\DBAL\Connection;

final class ServiceSaasRepository
{
    private readonly SaasSchemaManager $schemaManager;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->schemaManager = new SaasSchemaManager($this->connection);
    }

    public function insert(ServiceSaasRecord $record): ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();

        $now = gmdate('Y-m-d H:i:s');
        $createdAt = $record->createdAt ?? $now;
        $updatedAt = $record->updatedAt ?? $now;

        $this->connection->insert(SaasSchemaManager::TABLE_NAME, [
            'client_id' => $record->clientId,
            'order_id' => $record->orderId,
            'app_id' => $record->appId,
            'plan_id' => $record->planId,
            'target_email' => strtolower(trim($record->targetEmail)),
            'external_user_id' => $record->externalUserId,
            'provisioning_status' => $record->provisioningStatus !== ''
                ? $record->provisioningStatus
                : ServiceSaasRecord::STATUS_PENDING_CLAIM,
            'plan_metadata' => $this->encodeMetadata($record->planMetadata),
            'return_redirect_url' => $record->returnRedirectUrl,
            'last_sync_at' => $record->lastSyncAt,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $insertedId = (int) $this->connection->lastInsertId();
        $record->id = $insertedId;
        $record->createdAt = $createdAt;
        $record->updatedAt = $updatedAt;

        return $this->findById($insertedId) ?? $record;
    }

    public function update(ServiceSaasRecord $record): ServiceSaasRecord
    {
        if ($record->id === null) {
            throw new \InvalidArgumentException('Cannot update a ServiceSaasRecord without an id.');
        }

        $this->schemaManager->ensureTableExists();
        $updatedAt = gmdate('Y-m-d H:i:s');

        $this->connection->update(
            SaasSchemaManager::TABLE_NAME,
            [
                'client_id' => $record->clientId,
                'order_id' => $record->orderId,
                'app_id' => $record->appId,
                'plan_id' => $record->planId,
                'target_email' => strtolower(trim($record->targetEmail)),
                'external_user_id' => $record->externalUserId,
                'provisioning_status' => $record->provisioningStatus,
                'plan_metadata' => $this->encodeMetadata($record->planMetadata),
                'return_redirect_url' => $record->returnRedirectUrl,
                'last_sync_at' => $record->lastSyncAt,
                'updated_at' => $updatedAt,
            ],
            ['id' => $record->id],
        );

        $record->updatedAt = $updatedAt;

        return $this->findById($record->id) ?? $record;
    }

    public function findById(int $id): ?ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . SaasSchemaManager::TABLE_NAME . ' WHERE id = :id',
            ['id' => $id],
        );

        return is_array($row) ? ServiceSaasRecord::fromRow($row) : null;
    }

    public function findByOrderId(int $orderId): ?ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . SaasSchemaManager::TABLE_NAME . ' WHERE order_id = :order_id ORDER BY id DESC LIMIT 1',
            ['order_id' => $orderId],
        );

        return is_array($row) ? ServiceSaasRecord::fromRow($row) : null;
    }

    public function findPendingOrActiveByEmail(string $appId, string $email): ?ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();

        $normalizedEmail = strtolower(trim($email));
        $sql = 'SELECT * FROM ' . SaasSchemaManager::TABLE_NAME
            . ' WHERE app_id = :app_id AND LOWER(target_email) = :email'
            . ' AND provisioning_status IN (:pending_status, :active_status)'
            . ' ORDER BY CASE WHEN provisioning_status = :pending_status THEN 0 ELSE 1 END, id DESC';

        $rows = $this->connection->fetchAllAssociative($sql, [
            'app_id' => $appId,
            'email' => $normalizedEmail,
            'pending_status' => ServiceSaasRecord::STATUS_PENDING_CLAIM,
            'active_status' => ServiceSaasRecord::STATUS_PROVISIONED,
        ]);

        return $this->pickFirstRecord($rows);
    }

    public function findByExternalUserId(string $appId, string $externalUserId): ?ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . SaasSchemaManager::TABLE_NAME
            . ' WHERE app_id = :app_id AND external_user_id = :external_user_id ORDER BY id DESC',
            [
                'app_id' => $appId,
                'external_user_id' => $externalUserId,
            ],
        );

        return $this->pickFirstRecord($rows);
    }

    public function findActiveSubscriptionByEmail(string $appId, string $email): ?ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();
        $sql = 'SELECT * FROM ' . SaasSchemaManager::TABLE_NAME
            . ' WHERE app_id = :app_id AND LOWER(target_email) = :email'
            . ' AND provisioning_status IN (:pending_status, :active_status)'
            . ' ORDER BY CASE WHEN provisioning_status = :pending_status THEN 0 ELSE 1 END, id DESC';

        $rows = $this->connection->fetchAllAssociative($sql, [
            'app_id' => $appId,
            'email' => strtolower(trim($email)),
            'pending_status' => ServiceSaasRecord::STATUS_PENDING_CLAIM,
            'active_status' => ServiceSaasRecord::STATUS_PROVISIONED,
        ]);

        return $this->pickFirstSubscriptionRecord($rows);
    }

    public function findActiveSubscriptionByExternalUserId(string $appId, string $externalUserId): ?ServiceSaasRecord
    {
        $this->schemaManager->ensureTableExists();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . SaasSchemaManager::TABLE_NAME
            . ' WHERE app_id = :app_id AND external_user_id = :external_user_id ORDER BY id DESC',
            ['app_id' => $appId, 'external_user_id' => $externalUserId],
        );

        return $this->pickFirstSubscriptionRecord($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function pickFirstRecord(array $rows): ?ServiceSaasRecord
    {
        return $rows !== [] ? ServiceSaasRecord::fromRow($rows[0]) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function pickFirstSubscriptionRecord(array $rows): ?ServiceSaasRecord
    {
        foreach ($rows as $row) {
            $record = ServiceSaasRecord::fromRow($row);
            $itemType = strtolower((string) ($record->planMetadata['item_type'] ?? $record->planMetadata['kind'] ?? 'plan'));
            if (!in_array($itemType, ['addon', 'topup', 'overage'], true)) {
                return $record;
            }
        }

        return null;
    }

    public function deleteById(int $id): void
    {
        $this->schemaManager->ensureTableExists();

        $this->connection->delete(SaasSchemaManager::TABLE_NAME, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return '{}';
        }
    }
}
