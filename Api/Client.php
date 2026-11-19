<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Api;

require_once dirname(__DIR__) . '/src/Compat.php';

use Box\Mod\Servicesaas\Compat\AbstractApi;
use Box\Mod\Servicesaas\Compat\InformationException;
use Box\Mod\Servicesaas\Compat\RuntimeCompat;
use Box\Mod\Servicesaas\Service;

class Client extends AbstractApi
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function status(array $data): array
    {
        return $this->saasService()->getStatusForOrder($this->requireOrderId($data), $this->requireClientId());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function sync_claim(array $data): array
    {
        return $this->saasService()->syncClaimForOrder($this->requireOrderId($data), $this->requireClientId());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{url: ?string}
     */
    public function return_url(array $data): array
    {
        return [
            'url' => $this->saasService()->resolveValidatedReturnUrl($this->requireOrderId($data), $this->requireClientId()),
        ];
    }

    /** @param array<string, mixed> $data */
    private function requireOrderId(array $data): int
    {
        if (empty($data['order_id'])) {
            throw new InformationException('Order ID is required');
        }

        return (int) $data['order_id'];
    }

    private function requireClientId(): int
    {
        $clientId = RuntimeCompat::clientId($this->getIdentity());
        if ($clientId === null) {
            throw new InformationException('Client authentication is required.', [], 403);
        }

        return $clientId;
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
