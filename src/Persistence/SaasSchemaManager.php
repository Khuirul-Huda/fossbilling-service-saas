<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

final class SaasSchemaManager
{
    public const string TABLE_NAME = 'service_saas';
    private const int APP_ID_LENGTH = 64;
    private const int PLAN_ID_LENGTH = 128;
    private const int EMAIL_LENGTH = 255;
    private const int EXTERNAL_USER_ID_LENGTH = 128;
    private const int STATUS_LENGTH = 32;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function ensureTableExists(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE_NAME])) {
            return;
        }

        $schemaManager->createTable($this->buildTableDefinition());
    }

    public function dropTableIfExists(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist([self::TABLE_NAME])) {
            return;
        }

        $schemaManager->dropTable(self::TABLE_NAME);
    }

    private function buildTableDefinition(): Table
    {
        return Table::editor()
            ->setUnquotedName(self::TABLE_NAME)
            ->setColumns(
                $this->column('id', Types::BIGINT)->setAutoincrement(true)->create(),
                $this->column('client_id', Types::BIGINT)->create(),
                $this->column('order_id', Types::BIGINT, false)->create(),
                $this->stringColumn('app_id', self::APP_ID_LENGTH)->create(),
                $this->stringColumn('plan_id', self::PLAN_ID_LENGTH)->create(),
                $this->stringColumn('target_email', self::EMAIL_LENGTH)->create(),
                $this->stringColumn('external_user_id', self::EXTERNAL_USER_ID_LENGTH, false)->create(),
                $this->stringColumn('provisioning_status', self::STATUS_LENGTH)
                    ->setDefaultValue(ServiceSaasRecord::STATUS_PENDING_CLAIM)
                    ->create(),
                $this->column('plan_metadata', Types::TEXT, false)->create(),
                $this->column('return_redirect_url', Types::TEXT, false)->create(),
                $this->column('last_sync_at', Types::DATETIME_MUTABLE, false)->create(),
                $this->column('created_at', Types::DATETIME_MUTABLE)->create(),
                $this->column('updated_at', Types::DATETIME_MUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
            )
            ->setIndexes(
                $this->index('idx_service_saas_order_id', 'order_id'),
                $this->index('idx_service_saas_client_id', 'client_id'),
                $this->index('idx_service_saas_app_email', 'app_id', 'target_email'),
                $this->index('idx_service_saas_app_ext_user', 'app_id', 'external_user_id'),
            )
            ->create();
    }

    /**
     * @param non-empty-string $name
     */
    private function column(string $name, string $typeName, bool $notNull = true): \Doctrine\DBAL\Schema\ColumnEditor
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($typeName)
            ->setNotNull($notNull);
    }

    /**
     * @param non-empty-string $name
     */
    private function stringColumn(string $name, int $length, bool $notNull = true): \Doctrine\DBAL\Schema\ColumnEditor
    {
        return $this->column($name, Types::STRING, $notNull)->setLength($length);
    }

    /**
     * @param non-empty-string $indexName
     * @param non-empty-string ...$columns
     */
    private function index(string $indexName, string ...$columns): Index
    {
        $editor = Index::editor()->setUnquotedName($indexName);
        foreach ($columns as $column) {
            $editor->addUnquotedColumnName($column);
        }

        return $editor->create();
    }
}
