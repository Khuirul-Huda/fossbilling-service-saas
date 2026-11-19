<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Box\Mod\Servicesaas\Client\AppConfig;
use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;

final class CheckoutBridge
{
    private const int GENERATED_PASSWORD_BYTES = 16;

    private ?\Pimple\Container $di = null;
    private readonly CheckoutOrderHelper $orderHelper;

    public function __construct(
        private readonly EncryptedChannel $channel,
        private readonly AppRegistry $registry,
        private readonly ServiceSaasRepository $repository,
        ?CheckoutOrderHelper $orderHelper = null,
    ) {
        $this->orderHelper = $orderHelper ?? new CheckoutOrderHelper();
    }

    public function setDi(\Pimple\Container $di): self
    {
        $this->di = $di;

        return $this;
    }

    /**
     * @param array<string, mixed> $rawEnvelope
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function handleEncryptedRequest(
        array $rawEnvelope,
        ?\Closure $orderCreator = null,
        ?\Closure $baseUrlResolver = null,
    ): array {
        $envelope = EnvelopeDto::fromArray($rawEnvelope);
        $appConfig = $this->registry->get($envelope->appId);
        $request = CheckoutRequest::fromDecryptedPayload($this->channel->open($envelope, $appConfig->sharedSecret));
        $this->assertValidTarget($envelope, $request, $appConfig);

        $existing = $this->findActiveRecord($request);
        if ($existing !== null) {
            $existing = $this->updateExistingRecord($existing, $request);
        }

        $creator = $orderCreator ?? $this->createOrderWithDi(...);
        $orderResult = $creator($this->buildCreatorInput($request, $existing));
        $fallbackOrderId = $existing !== null ? ($existing->orderId ?? 0) : 0;
        $orderId = (int) ($orderResult['order_id'] ?? $fallbackOrderId);
        $invoiceHash = (string) ($orderResult['invoice_hash'] ?? '');
        if ($orderId <= 0 || $invoiceHash === '') {
            throw new \RuntimeException('Checkout order creator must return a valid order_id and invoice_hash.');
        }

        if ($existing === null) {
            $this->ensureSaasRecordExists($request, $orderId, (int) ($orderResult['client_id'] ?? 0));
        }

        return $this->channel->seal($request->appId, [
            'status' => 'ok',
            'order_id' => $orderId,
            'invoice_hash' => $invoiceHash,
            'checkout_url' => $this->resolveCheckoutUrl($orderResult, $invoiceHash, $baseUrlResolver),
        ], $appConfig->sharedSecret)->toArray();
    }

    private function assertValidTarget(EnvelopeDto $envelope, CheckoutRequest $request, AppConfig $appConfig): void
    {
        if ($request->appId !== $envelope->appId) {
            throw new \InvalidArgumentException('Envelope app_id does not match checkout payload app_id.');
        }
        if ($request->redirectUrl !== null && !$appConfig->isRedirectUrlAllowed($request->redirectUrl)) {
            throw new \InvalidArgumentException('Redirect URL is not allowed for this SaaS application.');
        }
    }

    private function findActiveRecord(CheckoutRequest $request): ?ServiceSaasRecord
    {
        if ($request->externalUserId !== '') {
            $byExternal = $this->repository->findByExternalUserId($request->appId, $request->externalUserId);
            if ($this->isActiveOrderRecord($byExternal)) {
                return $byExternal;
            }
        }

        $byEmail = $this->repository->findPendingOrActiveByEmail($request->appId, $request->email);

        return $this->isActiveOrderRecord($byEmail) ? $byEmail : null;
    }

    private function isActiveOrderRecord(?ServiceSaasRecord $record): bool
    {
        if ($record === null || $record->orderId === null) {
            return false;
        }

        return in_array($record->provisioningStatus, [
            ServiceSaasRecord::STATUS_PENDING_CLAIM,
            ServiceSaasRecord::STATUS_PROVISIONED,
        ], true);
    }

    private function updateExistingRecord(ServiceSaasRecord $record, CheckoutRequest $request): ServiceSaasRecord
    {
        if ($record->provisioningStatus === ServiceSaasRecord::STATUS_PENDING_CLAIM) {
            $record->planId = $request->planId;
            $record->planMetadata = $request->planMetadata;
        }
        $record->targetEmail = $request->email;
        $record->returnRedirectUrl = $request->redirectUrl ?? $record->returnRedirectUrl;
        if ($request->externalUserId !== '') {
            $record->externalUserId = $request->externalUserId;
        }

        return $this->repository->update($record);
    }

    /** @return array<string, mixed> */
    private function buildCreatorInput(CheckoutRequest $request, ?ServiceSaasRecord $existing): array
    {
        return [
            'app_id' => $request->appId,
            'external_user_id' => $request->externalUserId,
            'email' => $request->email,
            'plan_id' => $request->planId,
            'action' => $request->action,
            'redirect_url' => $request->redirectUrl,
            'plan_metadata' => $request->planMetadata,
            'existing_order_id' => $existing?->orderId,
            'client_id' => $existing?->clientId,
        ];
    }

    private function ensureSaasRecordExists(CheckoutRequest $request, int $orderId, int $clientId): void
    {
        if ($this->repository->findByOrderId($orderId) !== null) {
            return;
        }

        $this->repository->insert(new ServiceSaasRecord([
            'client_id' => $clientId,
            'order_id' => $orderId,
            'app_id' => $request->appId,
            'plan_id' => $request->planId,
            'target_email' => $request->email,
            'external_user_id' => $request->externalUserId !== '' ? $request->externalUserId : null,
            'provisioning_status' => ServiceSaasRecord::STATUS_PENDING_CLAIM,
            'plan_metadata' => $request->planMetadata,
            'return_redirect_url' => $request->redirectUrl,
        ]));
    }

    /** @param array<string, mixed> $orderResult */
    private function resolveCheckoutUrl(array $orderResult, string $invoiceHash, ?\Closure $baseUrlResolver): string
    {
        $explicitUrl = $orderResult['checkout_url'] ?? null;
        if (is_string($explicitUrl) && trim($explicitUrl) !== '') {
            return trim($explicitUrl);
        }

        $defaultBase = defined('SYSTEM_URL') ? (string) SYSTEM_URL : '';
        $baseUrl = $baseUrlResolver !== null ? (string) $baseUrlResolver($invoiceHash) : $defaultBase;
        $trimmedBase = rtrim($baseUrl, '/');

        return $trimmedBase === '' ? '/invoice/' . $invoiceHash : $trimmedBase . '/invoice/' . $invoiceHash;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{order_id: int, invoice_hash: string, client_id: int}
     */
    private function createOrderWithDi(array $payload): array
    {
        $di = $this->di ?? throw new \RuntimeException('DI container or orderCreator closure is required.');
        $orderConfig = array_filter([
            'app_id' => $payload['app_id'],
            'plan_id' => $payload['plan_id'],
            'target_email' => $payload['email'],
            'external_user_id' => $payload['external_user_id'],
            'plan_metadata' => $payload['plan_metadata'] !== [] ? $payload['plan_metadata'] : null,
            'return_redirect_url' => is_string($payload['redirect_url']) && $payload['redirect_url'] !== '' ? $payload['redirect_url'] : null,
        ], static fn (mixed $v): bool => $v !== null);

        $existingOrderId = $payload['existing_order_id'] ?? null;
        if (is_int($existingOrderId) && $existingOrderId > 0) {
            return $this->issueInvoiceForExistingOrder($di, $existingOrderId, $payload, $orderConfig);
        }

        $client = $this->findOrCreateClient($di, (string) $payload['email']);
        $product = $this->findMatchingProduct($di, (string) $payload['app_id'], (string) $payload['plan_id']);
        $period = $this->orderHelper->resolvePeriodCode(
            $product,
            (string) $payload['plan_id'],
            is_array($payload['plan_metadata'] ?? null) ? $payload['plan_metadata'] : [],
        );
        $createParams = [
            'invoice_option' => 'issue-invoice',
            'config' => $orderConfig,
        ];
        if ($period !== null) {
            $createParams['period'] = $period;
        }
        $orderId = (int) $di['mod_service']('order')->createOrder($client, $product, $createParams);
        $order = $di['em']->getRepository(Order::class)->find($orderId)
            ?? throw new \RuntimeException('Created order could not be reloaded.');

        return [
            'order_id' => $orderId,
            'invoice_hash' => $this->resolveOrderInvoiceHash($di, $order),
            'client_id' => (int) $client->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $orderConfig
     *
     * @return array{order_id: int, invoice_hash: string, client_id: int}
     */
    private function issueInvoiceForExistingOrder(
        \Pimple\Container $di,
        int $orderId,
        array $payload,
        array $orderConfig,
    ): array {
        $order = $di['em']->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            throw new \RuntimeException(sprintf('Existing order #%d was not found.', $orderId));
        }

        $product = $this->findMatchingProduct($di, (string) $payload['app_id'], (string) $payload['plan_id']);
        $payload['currency'] = $order->getCurrency() ?: 'IDR';
        $target = $this->orderHelper->resolveTargetPlanDetails($di, $product, $payload);
        $this->orderHelper->applyCheckoutUpdateToOrder($di, $order, $target, $orderConfig);

        return [
            'order_id' => $orderId,
            'invoice_hash' => $this->resolveOrderInvoiceHash($di, $order),
            'client_id' => (int) $order->getClientId(),
        ];
    }

    private function resolveOrderInvoiceHash(\Pimple\Container $di, Order $order): string
    {
        $unpaidId = $order->getUnpaidInvoiceId();
        $invoice = $unpaidId !== null ? $di['em']->getRepository(Invoice::class)->find($unpaidId) : null;

        if (!$invoice instanceof Invoice || $invoice->getStatus() !== Invoice::STATUS_UNPAID) {
            $invoiceService = $di['mod_service']('invoice');
            $invoice = $invoiceService->generateForOrder($order);
            $this->orderHelper->applyPendingUpgradeToDraftInvoice($di, $invoice, $order);
            $di['mod_service']('order')->setUnpaidInvoice($order, $invoice);
            if (!$invoice->isIssued()) {
                $invoiceService->issueInvoice($invoice, ['id' => $invoice->getId(), 'use_credits' => true]);
            }
        }

        $hash = (string) $invoice->getHash();
        if ($hash === '') {
            throw new \RuntimeException('Generated invoice is missing a public hash.');
        }

        return $hash;
    }

    private function findOrCreateClient(\Pimple\Container $di, string $email): Client
    {
        $clientRepo = $di['em']->getRepository(Client::class);
        $existing = $clientRepo->findOneBy(['email' => $email]);
        if ($existing instanceof Client) {
            return $existing;
        }

        $clientId = (int) $di['mod_service']('client')->adminCreateClient([
            'email' => $email,
            'first_name' => 'SaaS',
            'last_name' => 'Customer',
            'password' => bin2hex(random_bytes(self::GENERATED_PASSWORD_BYTES)),
            'send_welcome_email' => false,
        ]);

        return $clientRepo->find($clientId)
            ?? throw new \RuntimeException('Failed to create client account for SaaS checkout.');
    }

    private function findMatchingProduct(\Pimple\Container $di, string $appId, string $planId): Product
    {
        foreach ($di['em']->getRepository(Product::class)->findBy(['type' => 'saas']) as $product) {
            $config = $product->getConfig() ?? [];
            if (($config['app_id'] ?? '') === $appId && ($config['plan_id'] ?? '') === $planId) {
                return $product;
            }
        }

        throw new \RuntimeException(sprintf('No SaaS product configured for app "%s" and plan "%s".', $appId, $planId));
    }
}
