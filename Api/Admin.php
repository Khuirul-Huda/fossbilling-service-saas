<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Api;

require_once dirname(__DIR__) . '/src/Compat.php';

use Box\Mod\Servicesaas\Client\AppConfig;
use Box\Mod\Servicesaas\Compat\AbstractApi;
use Box\Mod\Servicesaas\Compat\InformationException;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Service;

class Admin extends AbstractApi
{
    private const string MASKED_SECRET = '********';

    /** @param array<string, mixed> $data */
    public function save_app(array $data): bool
    {
        $this->checkPermissions('servicesaas', 'manage_settings');
        $service = $this->saasService();
        $extensionService = $this->getDi()['mod_service']('extension');
        $config = $extensionService->getConfig('mod_servicesaas');
        $apps = is_array($config['apps'] ?? null) ? $config['apps'] : [];

        $deleteId = trim((string) ($data['delete_app_id'] ?? ''));
        if ($deleteId !== '') {
            unset($apps[$deleteId]);

            return $this->persistApps($extensionService, $apps, $service);
        }

        $appId = trim((string) ($data['app_id'] ?? ''));
        $existing = is_array($apps[$appId] ?? null) ? $apps[$appId] : [];
        $forceGenerate = filter_var($data['generate_secret'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $secret = trim((string) ($data['shared_secret'] ?? ''));
        if ($forceGenerate) {
            $secret = EncryptedChannel::generateHexSecret();
        } elseif ($secret === '' || $secret === self::MASKED_SECRET) {
            $storedSecret = trim((string) ($existing['shared_secret'] ?? ''));
            $secret = $storedSecret !== '' ? $storedSecret : EncryptedChannel::generateHexSecret();
        }

        $rawSyncFlag = $data['allow_catalog_sync'] ?? ($existing['allow_catalog_sync'] ?? true);
        $allowCatalogSync = filter_var($rawSyncFlag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $validated = AppConfig::fromArray([
            'app_id' => $appId,
            'name' => $data['name'] ?? $appId,
            'base_url' => $data['base_url'] ?? '',
            'webhook_path' => $data['webhook_path'] ?? AppConfig::DEFAULT_WEBHOOK_PATH,
            'shared_secret' => $secret,
            'allowed_redirect_hosts' => $data['allowed_redirect_hosts'] ?? [],
            'allowed_custom_schemes' => $data['allowed_custom_schemes'] ?? AppConfig::DEFAULT_CUSTOM_SCHEMES,
            'allow_catalog_sync' => $allowCatalogSync,
        ]);

        $apps[$validated->appId] = [
            'app_id' => $validated->appId,
            'name' => $validated->name,
            'base_url' => $validated->baseUrl,
            'webhook_path' => $validated->webhookPath,
            'shared_secret' => $validated->sharedSecret,
            'allowed_redirect_hosts' => $validated->allowedRedirectHosts,
            'allowed_custom_schemes' => $validated->allowedCustomSchemes,
            'allow_catalog_sync' => $validated->allowCatalogSync,
        ];

        return $this->persistApps($extensionService, $apps, $service);
    }

    /**
     * Generates a fresh 32-byte (64-char hex) libsodium shared secret, optionally rotating an existing app.
     *
     * @param array<string, mixed> $data
     *
     * @return array{shared_secret: string, rotated: bool}
     */
    public function generate_secret(array $data = []): array
    {
        $this->checkPermissions('servicesaas', 'manage_settings');
        $secret = EncryptedChannel::generateHexSecret();
        $appId = trim((string) ($data['app_id'] ?? ''));
        $persist = filter_var($data['persist'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($appId !== '' && $persist) {
            $extensionService = $this->getDi()['mod_service']('extension');
            $config = $extensionService->getConfig('mod_servicesaas');
            $apps = is_array($config['apps'] ?? null) ? $config['apps'] : [];
            if (is_array($apps[$appId] ?? null)) {
                $apps[$appId]['shared_secret'] = $secret;
                $this->persistApps($extensionService, $apps, $this->saasService());

                return ['shared_secret' => $secret, 'rotated' => true];
            }
        }

        return ['shared_secret' => $secret, 'rotated' => false];
    }

    /**
     * Returns copyable pairing configuration and .env snippet for a registered SaaS app.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function pairing_config(array $data): array
    {
        $this->checkPermissions('servicesaas', 'manage_settings');
        $appId = trim((string) ($data['app_id'] ?? ''));
        if ($appId === '') {
            throw new InformationException('App ID is required');
        }

        $app = $this->saasService()->getAppRegistry()->get($appId);
        $baseUrl = defined('SYSTEM_URL') ? rtrim((string) SYSTEM_URL, '/') : '';
        $payload = [
            'fossbilling_base_url' => $baseUrl,
            'app_id' => $app->appId,
            'shared_secret' => $app->sharedSecret,
            'webhook_url' => $app->getWebhookUrl(),
        ];

        return [
            ...$payload,
            'env_snippet' => implode("\n", [
                'FOSSBILLING_BASE_URL=' . $baseUrl,
                'FOSSBILLING_APP_ID=' . $app->appId,
                'FOSSBILLING_SHARED_SECRET=' . $app->sharedSecret,
            ]),
            'pairing_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public function list_apps(array $data = []): array
    {
        $this->checkPermissions('servicesaas', 'manage');
        $items = [];
        foreach ($this->saasService()->getAppRegistry()->all() as $app) {
            $items[] = [
                'app_id' => $app->appId,
                'name' => $app->name,
                'base_url' => $app->baseUrl,
                'webhook_path' => $app->webhookPath,
                'webhook_url' => $app->getWebhookUrl(),
                'allowed_redirect_hosts' => implode(', ', $app->allowedRedirectHosts),
                'allowed_custom_schemes' => implode(', ', $app->allowedCustomSchemes),
                'allow_catalog_sync' => $app->allowCatalogSync,
                'has_secret' => $app->sharedSecret !== '',
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function resync_order(array $data): array
    {
        $this->checkPermissions('servicesaas', 'manage');
        if (empty($data['order_id'])) {
            throw new InformationException('Order ID is required');
        }

        return $this->saasService()->syncClaimForOrder((int) $data['order_id']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function status(array $data): array
    {
        $this->checkPermissions('servicesaas', 'manage');
        if (empty($data['order_id'])) {
            throw new InformationException('Order ID is required');
        }

        return $this->saasService()->getStatusForOrder((int) $data['order_id']);
    }

    /** @param array<string, mixed> $apps */
    private function persistApps(object $extensionService, array $apps, Service $service): bool
    {
        $extensionService->setConfig(['ext' => 'mod_servicesaas', 'apps' => $apps]);
        $service->resetRegistry();

        return true;
    }

    private function saasService(): Service
    {
        $service = $this->getService();
        if (!$service instanceof Service) {
            throw new \RuntimeException('Servicesaas Service is unavailable.');
        }

        return $service;
    }
}
