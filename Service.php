<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Box\Mod\Servicesaas\Bridge\CheckoutBridge;
use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Client\ExpirationCalculator;
use Box\Mod\Servicesaas\Client\ProvisioningClient;
use Box\Mod\Servicesaas\Client\ProvisioningPayload;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\SaasSchemaManager;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;
use FOSSBilling\Core\Container\InjectionAwareInterface;
use FOSSBilling\Core\Exception\InformationException;

class Service implements InjectionAwareInterface
{
    private const int HTTP_NOT_FOUND = 404;

    protected ?\Pimple\Container $di = null;

    public function __construct(
        private ?ServiceSaasRepository $repository = null,
        private ?ProvisioningClient $provisioningClient = null,
        private ?AppRegistry $registry = null,
    ) {
        self::registerSubNamespaceAutoloader();
    }

    public function setDi(\Pimple\Container $di): void
    {
        self::registerSubNamespaceAutoloader();
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container { return $this->di; }

    /** @return array<string, mixed> */
    public function getModulePermissions(): array
    {
        return [
            'manage' => ['type' => 'bool', 'display_name' => __trans('Manage SaaS services'), 'description' => __trans('Allows managing SaaS apps and resyncing orders.')],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        (new SaasSchemaManager($this->requireDi()['em']->getConnection()))->ensureTableExists();

        return true;
    }

    public function uninstall(): bool
    {
        (new SaasSchemaManager($this->requireDi()['em']->getConnection()))->dropTableIfExists();

        return true;
    }

    /** @return list<string> */
    public function clientSettableConfigKeys(): array { return ['period', 'quantity', 'target_email']; }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function attachOrderConfig(Product $product, array $data): array
    {
        return array_merge($product->getConfig() ?? [], $data);
    }

    /** @param Order|array<string, mixed> $order */
    public function create(Order|array $order): ServiceSaasRecord
    {
        $ctx = $this->extractOrderContext($order);
        $existing = $ctx['order_id'] !== null ? $this->getRepository()->findByOrderId($ctx['order_id']) : null;

        return $existing ?? $this->getRepository()->insert($this->buildRecord($ctx));
    }

    /** @param Order|array<string, mixed> $order */
    public function activate(Order|array $order, mixed $service = null): bool { return $this->runTimedAction($order, 'activate'); }
    /** @param Order|array<string, mixed> $order */
    public function renew(Order|array $order, mixed $service = null): bool { return $this->runTimedAction($order, 'renew'); }
    /** @param Order|array<string, mixed> $order */
    public function unsuspend(Order|array $order, mixed $service = null): bool { return $this->runTimedAction($order, 'unsuspend'); }
    /** @param Order|array<string, mixed> $order */
    public function uncancel(Order|array $order, mixed $service = null): bool { return $this->runTimedAction($order, 'uncancel'); }
    /** @param Order|array<string, mixed> $order */
    public function suspend(Order|array $order, mixed $service = null): bool { return $this->dispatchProvisioning($this->extractOrderContext($order), ['action' => 'suspend', 'forced_status' => ServiceSaasRecord::STATUS_SUSPENDED]); }
    /** @param Order|array<string, mixed> $order */
    public function cancel(Order|array $order, mixed $service = null): bool { return $this->dispatchProvisioning($this->extractOrderContext($order), ['action' => 'cancel', 'forced_status' => ServiceSaasRecord::STATUS_CANCELED]); }

    /** @param Order|array<string, mixed> $order */
    public function delete(Order|array $order, mixed $service = null): void
    {
        $ctx = $this->extractOrderContext($order);
        $record = $ctx['order_id'] !== null ? $this->getRepository()->findByOrderId($ctx['order_id']) : null;
        if ($record !== null && $record->id !== null) {
            $this->getRepository()->deleteById($record->id);
        }
    }

    public function action_create(Order|array $order): ServiceSaasRecord { return $this->create($order); }
    public function action_activate(Order|array $order): bool { return $this->activate($order); }
    public function action_renew(Order|array $order): bool { return $this->renew($order); }
    public function action_suspend(Order|array $order): bool { return $this->suspend($order); }
    public function action_unsuspend(Order|array $order): bool { return $this->unsuspend($order); }
    public function action_cancel(Order|array $order): bool { return $this->cancel($order); }
    public function action_uncancel(Order|array $order): bool { return $this->uncancel($order); }
    public function action_delete(Order|array $order, bool $forceDelete = false): void { $this->delete($order); }

    /**
     * @param ServiceSaasRecord|array<string, mixed> $model
     * @return array<string, mixed>
     */
    public function toApiArray(ServiceSaasRecord|array $model): array
    {
        return $model instanceof ServiceSaasRecord ? $model->toApiArray() : $model;
    }

    /** @return array<string, mixed> */
    public function getStatusForOrder(int $orderId, ?int $clientId = null): array
    {
        $record = $this->requireOrderRecord($orderId, $clientId);
        $data = $record->toApiArray();
        $data['app_name'] = $this->getAppRegistry()->has($record->appId) ? $this->getAppRegistry()->get($record->appId)->name : $record->appId;

        return $data;
    }

    /** @return array<string, mixed> */
    public function syncClaimForOrder(int $orderId, ?int $clientId = null): array
    {
        $record = $this->requireOrderRecord($orderId, $clientId);
        $order = $this->di !== null ? $this->di['em']->getRepository(Order::class)->find($orderId) : null;
        $order instanceof Order ? $this->activate($order) : $this->activate(['id' => $orderId, 'client_id' => $record->clientId]);

        return $this->getStatusForOrder($orderId, $clientId);
    }

    public function resolveValidatedReturnUrl(int $orderId, ?int $clientId = null): ?string
    {
        $record = $this->requireOrderRecord($orderId, $clientId);
        $rawUrl = trim((string) ($record->returnRedirectUrl ?? ''));
        $registry = $this->getAppRegistry();
        if ($rawUrl === '' || !$registry->has($record->appId) || !$registry->get($record->appId)->isRedirectUrlAllowed($rawUrl)) {
            return null;
        }
        $parts = explode('#', $rawUrl, 2);
        $result = $parts[0] . (str_contains($parts[0], '?') ? '&' : '?') . 'status=success';

        return isset($parts[1]) ? $result . '#' . $parts[1] : $result;
    }

    public function getRepository(): ServiceSaasRepository
    {
        return $this->repository ??= new ServiceSaasRepository($this->requireDi()['em']->getConnection());
    }

    public function getAppRegistry(): AppRegistry
    {
        $cfg = $this->registry === null && $this->di !== null ? $this->di['mod_config']('servicesaas') : [];

        return $this->registry ??= new AppRegistry(is_array($cfg) ? $cfg : []);
    }

    public function resetRegistry(): void
    {
        $this->registry = null;
        $this->provisioningClient = null;
    }

    /** @param Order|array<string, mixed> $order */
    private function runTimedAction(Order|array $order, string $action): bool
    {
        $ctx = $this->extractOrderContext($order);
        $calc = new ExpirationCalculator();
        $logic = $this->di !== null ? (string) ($this->di['mod_config']('order')['order_renewal_logic'] ?? ExpirationCalculator::LOGIC_FROM_GREATER) : ExpirationCalculator::LOGIC_FROM_GREATER;
        $exp = match ($action) {
            'renew' => $calc->calculateForRenewal($ctx['period'], $ctx['expires_at'], $logic),
            'unsuspend' => ($ctx['expires_at'] !== null && $ctx['expires_at'] > time()) ? $ctx['expires_at'] : $calc->calculateForActivation($ctx['period']),
            default => $calc->calculateForActivation($ctx['period']),
        };

        return $this->dispatchProvisioning($ctx, ['action' => $action, 'expires_at_micro' => ExpirationCalculator::toMicroseconds($exp)]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    private function dispatchProvisioning(array $context, array $options): bool
    {
        $forced = $options['forced_status'] ?? null;
        $record = $context['order_id'] !== null ? $this->getRepository()->findByOrderId($context['order_id']) : null;
        if ($record === null) {
            if ($forced !== null) {
                return true;
            }
            $record = $this->getRepository()->insert($this->buildRecord($context));
        }
        $res = ($this->provisioningClient ??= new ProvisioningClient(new EncryptedChannel(), $this->getAppRegistry()))->dispatch(new ProvisioningPayload([
            'action' => (string) $options['action'], 'app_id' => $record->appId, 'order_id' => $record->orderId,
            'client_id' => $record->clientId, 'email' => $record->targetEmail, 'external_user_id' => $record->externalUserId,
            'plan_id' => $record->planId, 'plan_metadata' => $record->planMetadata, 'expires_at_micro' => $options['expires_at_micro'] ?? null,
        ]));
        $state = strtolower(trim((string) ($res['provisioning_status'] ?? $res['status'] ?? '')));
        $record->provisioningStatus = $forced ?? (in_array($state, ['pending_registration', ServiceSaasRecord::STATUS_PENDING_CLAIM], true) ? ServiceSaasRecord::STATUS_PENDING_CLAIM : ServiceSaasRecord::STATUS_PROVISIONED);
        $extId = trim((string) ($res['external_user_id'] ?? ''));
        if ($extId !== '') {
            $record->externalUserId = $extId;
        }
        $record->lastSyncAt = gmdate('Y-m-d H:i:s');
        $this->getRepository()->update($record);

        return true;
    }

    /**
     * @param Order|array<string, mixed> $order
     * @return array{order_id: ?int, client_id: int, period: ?string, expires_at: ?int, config: array<string, mixed>}
     */
    private function extractOrderContext(Order|array $order): array
    {
        $isEntity = $order instanceof Order;
        $cfg = $isEntity ? ($order->getConfig() ?? []) : (is_array($order['config'] ?? null) ? $order['config'] : $order);
        if ($isEntity && $this->di !== null && $order->getProductId() !== null && (!isset($cfg['app_id']) || !isset($cfg['plan_id']) || empty($cfg['plan_metadata']))) {
            $product = $this->di['em']->getRepository(Product::class)->find($order->getProductId());
            $cfg = $product instanceof Product ? array_merge($product->getConfig() ?? [], $cfg) : $cfg;
        }
        $clientId = $isEntity ? (int) $order->getClientId() : (int) ($order['client_id'] ?? 0);
        if (trim((string) ($cfg['target_email'] ?? $cfg['email'] ?? '')) === '' && $this->di !== null && $clientId > 0) {
            $client = $this->di['em']->getRepository(Client::class)->find($clientId);
            $cfg['target_email'] = $client instanceof Client ? (string) ($client->getEmail() ?? '') : '';
        }
        $rawId = $isEntity ? $order->getId() : ($order['id'] ?? $order['order_id'] ?? null);
        $rawExp = $isEntity ? $order->getExpiresAt()?->getTimestamp() : ($order['expires_at'] ?? null);

        return [
            'order_id' => $rawId !== null && $rawId !== '' ? (int) $rawId : null,
            'client_id' => $clientId,
            'period' => $isEntity ? $order->getPeriod() : (isset($order['period']) && is_string($order['period']) ? $order['period'] : null),
            'expires_at' => is_int($rawExp) ? $rawExp : (is_string($rawExp) && $rawExp !== '' ? (strtotime($rawExp) ?: null) : null),
            'config' => $cfg,
        ];
    }

    /** @param array{order_id: ?int, client_id: int, period: ?string, expires_at: ?int, config: array<string, mixed>} $ctx */
    private function buildRecord(array $ctx): ServiceSaasRecord
    {
        $cfg = $ctx['config'];

        return new ServiceSaasRecord([
            'client_id' => $ctx['client_id'], 'order_id' => $ctx['order_id'],
            'app_id' => (string) ($cfg['app_id'] ?? 'default'), 'plan_id' => (string) ($cfg['plan_id'] ?? ''),
            'target_email' => (string) ($cfg['target_email'] ?? $cfg['email'] ?? ''),
            'external_user_id' => $cfg['external_user_id'] ?? null, 'provisioning_status' => ServiceSaasRecord::STATUS_PENDING_CLAIM,
            'plan_metadata' => ServiceSaasRecord::mergePlanMetadataFromConfig($cfg),
            'return_redirect_url' => $cfg['return_redirect_url'] ?? $cfg['redirect_url'] ?? null,
        ]);
    }

    private function requireOrderRecord(int $orderId, ?int $clientId): ServiceSaasRecord
    {
        $record = $this->getRepository()->findByOrderId($orderId);
        if ($record === null || ($clientId !== null && $record->clientId !== $clientId)) {
            throw new InformationException('SaaS service order was not found.', [], self::HTTP_NOT_FOUND);
        }

        return $record;
    }

    private function requireDi(): \Pimple\Container
    {
        return $this->di ?? throw new \RuntimeException('DI container has not been initialized on Servicesaas Service.');
    }

    private static function registerSubNamespaceAutoloader(): void
    {
        if (class_exists(CheckoutBridge::class, false)) {
            return;
        }
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, 'Box\\Mod\\Servicesaas\\') && is_file($file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Box\\Mod\\Servicesaas\\'))) . '.php')) {
                require_once $file;
            }
        });
    }
}
