<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Client;

final readonly class AppConfig
{
    public const string DEFAULT_WEBHOOK_PATH = '/billing/notify/fossbilling';
    /** @var list<string> */
    public const array DEFAULT_CUSTOM_SCHEMES = [];

    public string $appId;
    public string $name;
    public string $baseUrl;
    public string $webhookPath;
    public string $sharedSecret;

    /** @var list<string> */
    public array $allowedRedirectHosts;

    /** @var list<string> */
    public array $allowedCustomSchemes;

    /**
     * @param string|array<string, mixed> $appIdOrData
     */
    public function __construct(
        string|array $appIdOrData,
        string $name = '',
        string $baseUrl = '',
    ) {
        $parsed = is_array($appIdOrData)
            ? self::normalizeInputArray($appIdOrData)
            : self::normalizePositionalArgs(func_get_args(), $name, $baseUrl);

        $this->appId = $parsed['app_id'];
        $this->name = $parsed['name'];
        $this->baseUrl = $parsed['base_url'];
        $this->webhookPath = $parsed['webhook_path'];
        $this->sharedSecret = $parsed['shared_secret'];
        $this->allowedRedirectHosts = $parsed['allowed_redirect_hosts'];
        $this->allowedCustomSchemes = $parsed['allowed_custom_schemes'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function getWebhookUrl(): string
    {
        $trimmedBase = rtrim($this->baseUrl, '/');
        $trimmedPath = '/' . ltrim($this->webhookPath, '/');

        return $trimmedBase . $trimmedPath;
    }

    public function isRedirectUrlAllowed(string $url): bool
    {
        $candidate = trim($url);
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return false;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts) || !isset($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'http' || $scheme === 'https') {
            $host = strtolower((string) ($parts['host'] ?? ''));

            return $host !== '' && $this->isHostAllowed($host);
        }

        if (!in_array($scheme, $this->allowedCustomSchemes, true)) {
            return false;
        }

        return str_starts_with(strtolower($candidate), $scheme . '://')
            && strlen($candidate) > strlen($scheme . '://');
    }

    private function isHostAllowed(string $host): bool
    {
        foreach ($this->allowedRedirectHosts as $allowedHost) {
            $normalized = strtolower(trim($allowedHost));
            if ($normalized === '') {
                continue;
            }

            if ($host === $normalized || str_ends_with($host, '.' . $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     app_id: string,
     *     name: string,
     *     base_url: string,
     *     webhook_path: string,
     *     shared_secret: string,
     *     allowed_redirect_hosts: list<string>,
     *     allowed_custom_schemes: list<string>
     * }
     */
    private static function normalizeInputArray(array $data): array
    {
        $appId = trim((string) ($data['app_id'] ?? $data['appId'] ?? ''));
        $baseUrl = trim((string) ($data['base_url'] ?? $data['baseUrl'] ?? ''));
        $sharedSecret = (string) ($data['shared_secret'] ?? $data['sharedSecret'] ?? '');

        if ($appId === '' || $baseUrl === '' || $sharedSecret === '') {
            throw new \InvalidArgumentException('AppConfig requires non-empty app_id, base_url, and shared_secret.');
        }

        $name = trim((string) ($data['name'] ?? $appId));
        $webhookPath = trim((string) ($data['webhook_path'] ?? $data['webhookPath'] ?? self::DEFAULT_WEBHOOK_PATH));
        $hosts = self::parseStringList($data['allowed_redirect_hosts'] ?? $data['allowedRedirectHosts'] ?? []);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        if ($hosts === [] && is_string($baseHost) && $baseHost !== '') {
            $hosts = [strtolower($baseHost)];
        }

        $schemes = self::parseStringList(
            $data['allowed_custom_schemes'] ?? $data['allowedCustomSchemes'] ?? self::DEFAULT_CUSTOM_SCHEMES,
        );

        return [
            'app_id' => $appId,
            'name' => $name !== '' ? $name : $appId,
            'base_url' => $baseUrl,
            'webhook_path' => $webhookPath !== '' ? $webhookPath : self::DEFAULT_WEBHOOK_PATH,
            'shared_secret' => $sharedSecret,
            'allowed_redirect_hosts' => $hosts,
            'allowed_custom_schemes' => $schemes !== [] ? $schemes : self::DEFAULT_CUSTOM_SCHEMES,
        ];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array{
     *     app_id: string,
     *     name: string,
     *     base_url: string,
     *     webhook_path: string,
     *     shared_secret: string,
     *     allowed_redirect_hosts: list<string>,
     *     allowed_custom_schemes: list<string>
     * }
     */
    private static function normalizePositionalArgs(array $args, string $name, string $baseUrl): array
    {
        return self::normalizeInputArray([
            'app_id' => $args[0] ?? '',
            'name' => $name,
            'base_url' => $baseUrl,
            'webhook_path' => $args[3] ?? self::DEFAULT_WEBHOOK_PATH,
            'shared_secret' => $args[4] ?? '',
            'allowed_redirect_hosts' => $args[5] ?? [],
            'allowed_custom_schemes' => $args[6] ?? self::DEFAULT_CUSTOM_SCHEMES,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function parseStringList(mixed $raw): array
    {
        $items = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        $normalized = [];

        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $cleaned = strtolower(trim($item));
            if ($cleaned !== '' && !in_array($cleaned, $normalized, true)) {
                $normalized[] = $cleaned;
            }
        }

        return $normalized;
    }
}
