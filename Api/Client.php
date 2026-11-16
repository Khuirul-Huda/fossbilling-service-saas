<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Api;

use Box\Mod\Servicesaas\Service;
use FOSSBilling\Core\Api\AbstractApi;
use FOSSBilling\Core\Validation\Api\RequiredParams;

class Client extends AbstractApi
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function status(array $data): array
    {
        return $this->saasService()->getStatusForOrder((int) $data['order_id'], $this->requireClientId());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function sync_claim(array $data): array
    {
        return $this->saasService()->syncClaimForOrder((int) $data['order_id'], $this->requireClientId());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{url: ?string}
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function return_url(array $data): array
    {
        return [
            'url' => $this->saasService()->resolveValidatedReturnUrl((int) $data['order_id'], $this->requireClientId()),
        ];
    }

    private function requireClientId(): int
    {
        $identity = $this->getIdentity();
        if (!$identity instanceof \Box\Mod\Client\Entity\Client || $identity->getId() === null) {
            throw new \FOSSBilling\Core\Exception\InformationException('Client authentication is required.', [], 403);
        }

        return (int) $identity->getId();
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
