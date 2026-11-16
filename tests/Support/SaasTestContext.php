<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Tests\Support;

use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Persistence\SaasSchemaManager;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class SaasTestContext
{
    public const string TEST_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public static function createSqliteConnection(?string $dbPath = null): Connection
    {
        $params = $dbPath === null
            ? ['driver' => 'pdo_sqlite', 'memory' => true]
            : ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $connection = DriverManager::getConnection($params);
        (new SaasSchemaManager($connection))->ensureTableExists();

        return $connection;
    }

    public static function createRepository(?string $dbPath = null): ServiceSaasRepository
    {
        return new ServiceSaasRepository(self::createSqliteConnection($dbPath));
    }

    public static function createRegistry(string $baseUrl = 'https://api.cloudapp.test', ?string $secret = null): AppRegistry
    {
        return new AppRegistry([
            'apps' => [
                'cloudapp' => [
                    'app_id' => 'cloudapp',
                    'name' => 'CloudApp Storage',
                    'base_url' => $baseUrl,
                    'webhook_path' => '/billing/notify/fossbilling',
                    'shared_secret' => $secret ?? self::TEST_SECRET,
                    'allowed_redirect_hosts' => ['app.cloudapp.test', 'cloudapp.test'],
                    'allowed_custom_schemes' => ['cloudapp', 'saasdesktop'],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public static function seal(string $appId, array $payload, ?string $secret = null): array
    {
        return (new EncryptedChannel())->seal($appId, $payload, $secret ?? self::TEST_SECRET)->toArray();
    }

    /**
     * @param array<string, mixed> $rawEnvelope
     * @return array<string, mixed>
     */
    public static function open(array $rawEnvelope, ?string $secret = null): array
    {
        return (new EncryptedChannel())->open(EnvelopeDto::fromArray($rawEnvelope), $secret ?? self::TEST_SECRET);
    }
}
