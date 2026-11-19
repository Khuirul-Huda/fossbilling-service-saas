<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Api;

require_once dirname(__DIR__) . '/src/Compat.php';

use Box\Mod\Servicesaas\Bridge\AccountEventSync;
use Box\Mod\Servicesaas\Bridge\CheckoutBridge;
use Box\Mod\Servicesaas\Bridge\EntitlementClaimer;
use Box\Mod\Servicesaas\Compat\AbstractApi;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Service;

class Guest extends AbstractApi
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function checkout_session(array $data): array
    {
        $service = $this->saasService();
        $bridge = new CheckoutBridge(EncryptedChannel::persistent(), $service->getAppRegistry(), $service->getRepository());
        if ($this->di !== null) {
            $bridge->setDi($this->di);
        }

        return $bridge->handleEncryptedRequest($this->extractEnvelope($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function claim_entitlement(array $data): array
    {
        $service = $this->saasService();
        $claimer = new EntitlementClaimer(EncryptedChannel::persistent(), $service->getAppRegistry(), $service->getRepository());
        if ($this->di !== null) {
            $claimer->setDi($this->di);
        }

        return $claimer->handleEncryptedClaim($this->extractEnvelope($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function account_event(array $data): array
    {
        $service = $this->saasService();
        $sync = new AccountEventSync(EncryptedChannel::persistent(), $service->getAppRegistry(), $service->getRepository());

        return $sync->handleEncryptedEvent($this->extractEnvelope($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{is_saas: bool, status: string, return_url: ?string, pending_return_url: ?string, has_pending_manual_tx: bool}
     */
    public function invoice_return(array $data): array
    {
        return $this->saasService()->getInvoiceReturnContext((string) ($data['hash'] ?? ''));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function extractEnvelope(array $data): array
    {
        return is_array($data['envelope'] ?? null) ? $data['envelope'] : $data;
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
