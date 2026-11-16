<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Client;

final class AppRegistry
{
    private const string DEFAULT_APP_ID = 'default';
    private const int JSON_DECODE_DEPTH = 512;

    /** @var array<string, AppConfig> */
    private array $apps = [];

    /**
     * @param array<string, mixed> $moduleConfig
     */
    public function __construct(array $moduleConfig = [])
    {
        $this->loadFromModuleConfig($moduleConfig);
    }

    public function register(AppConfig $config): void
    {
        $this->apps[$config->appId] = $config;
    }

    public function get(string $appId): AppConfig
    {
        if (!isset($this->apps[$appId])) {
            throw new \RuntimeException(sprintf('SaaS application "%s" is not registered.', $appId));
        }

        return $this->apps[$appId];
    }

    public function has(string $appId): bool
    {
        return isset($this->apps[$appId]);
    }

    /**
     * @return array<string, AppConfig>
     */
    public function all(): array
    {
        return $this->apps;
    }

    /**
     * @param array<string, mixed> $moduleConfig
     */
    private function loadFromModuleConfig(array $moduleConfig): void
    {
        $appsConfig = $this->extractAppsList($moduleConfig['apps'] ?? null);
        foreach ($appsConfig as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['app_id']) && !isset($entry['appId']) && is_string($key) && $key !== '') {
                $entry['app_id'] = $key;
            }

            $this->registerIfValid($entry);
        }

        $this->loadFlatFallback($moduleConfig);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function extractAppsList(mixed $rawApps): array
    {
        if (is_array($rawApps)) {
            return $rawApps;
        }

        if (!is_string($rawApps) || trim($rawApps) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawApps, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $moduleConfig
     */
    private function loadFlatFallback(array $moduleConfig): void
    {
        $defaultAppId = (string) ($moduleConfig['default_app_id'] ?? self::DEFAULT_APP_ID);
        if ($this->has($defaultAppId)) {
            return;
        }

        $baseUrl = trim((string) ($moduleConfig['default_base_url'] ?? $moduleConfig['base_url'] ?? ''));
        $secret = (string) ($moduleConfig['default_shared_secret'] ?? $moduleConfig['shared_secret'] ?? '');

        if ($baseUrl === '' || $secret === '') {
            return;
        }

        $this->registerIfValid([
            'app_id' => $defaultAppId,
            'name' => (string) ($moduleConfig['default_app_name'] ?? $moduleConfig['app_name'] ?? 'Default SaaS App'),
            'base_url' => $baseUrl,
            'webhook_path' => (string) ($moduleConfig['default_webhook_path'] ?? $moduleConfig['webhook_path'] ?? AppConfig::DEFAULT_WEBHOOK_PATH),
            'shared_secret' => $secret,
            'allowed_redirect_hosts' => $moduleConfig['default_allowed_hosts'] ?? $moduleConfig['allowed_hosts'] ?? [],
            'allowed_custom_schemes' => $moduleConfig['default_allowed_schemes'] ?? $moduleConfig['allowed_schemes'] ?? AppConfig::DEFAULT_CUSTOM_SCHEMES,
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function registerIfValid(array $entry): void
    {
        try {
            $this->register(AppConfig::fromArray($entry));
        } catch (\InvalidArgumentException) {
            // Skip incomplete app entries in module configuration.
        }
    }
}
