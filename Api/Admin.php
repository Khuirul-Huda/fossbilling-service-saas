<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Api;

require_once dirname(__DIR__) . '/src/Compat.php';

use Box\Mod\Servicesaas\Client\AppConfig;
use Box\Mod\Servicesaas\Compat\AbstractApi;
use Box\Mod\Servicesaas\Compat\InformationException;
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
        $secret = trim((string) ($data['shared_secret'] ?? ''));
        if ($secret === '' || $secret === self::MASKED_SECRET) {
            $secret = (string) ($existing['shared_secret'] ?? '');
        }

        $validated = AppConfig::fromArray([
            'app_id' => $appId,
            'name' => $data['name'] ?? $appId,
            'base_url' => $data['base_url'] ?? '',
            'webhook_path' => $data['webhook_path'] ?? AppConfig::DEFAULT_WEBHOOK_PATH,
            'shared_secret' => $secret,
            'allowed_redirect_hosts' => $data['allowed_redirect_hosts'] ?? [],
            'allowed_custom_schemes' => $data['allowed_custom_schemes'] ?? AppConfig::DEFAULT_CUSTOM_SCHEMES,
        ]);

        $apps[$validated->appId] = [
            'app_id' => $validated->appId,
            'name' => $validated->name,
            'base_url' => $validated->baseUrl,
            'webhook_path' => $validated->webhookPath,
            'shared_secret' => $validated->sharedSecret,
            'allowed_redirect_hosts' => $validated->allowedRedirectHosts,
            'allowed_custom_schemes' => $validated->allowedCustomSchemes,
        ];

        return $this->persistApps($extensionService, $apps, $service);
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

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function qris_fallback_status(array $data): array
    {
        $this->checkPermissions('servicesaas', 'manage');
        $stored = $this->saasService()->getPrivateQrisStore()->read();
        if ($stored === null) {
            return ['configured' => false];
        }

        return [
            'configured' => true,
            'filename' => $stored['filename'],
            'mime' => $stored['mime'],
            'size' => $stored['size'],
            'uploaded_at' => $stored['uploaded_at'],
        ];
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
