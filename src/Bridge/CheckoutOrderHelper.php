<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Box\Mod\Product\Entity\ProductPayment;

/**
 * Resolves billing periods, target pricing, and deferred upgrade state for SaaS orders.
 */
final class CheckoutOrderHelper
{
    public const string PENDING_UPGRADE_KEY = 'pending_upgrade';

    public function resolvePeriodCode(Product $product, string $planId, array $planMetadata): ?string
    {
        $rawMeta = $planMetadata['period'] ?? null;
        if (is_string($rawMeta) && trim($rawMeta) !== '') {
            return $this->normalizePeriodCode($rawMeta);
        }

        $fromPlanId = $this->inferPeriodFromPlanId($planId);
        if ($fromPlanId !== null && $this->productSupportsPeriod($product, $fromPlanId)) {
            return $fromPlanId;
        }

        $productCfg = $product->getConfig() ?? [];
        if (is_string($productCfg['period'] ?? null) && trim($productCfg['period']) !== '') {
            return $this->normalizePeriodCode($productCfg['period']);
        }

        return $this->findFirstEnabledPeriod($product) ?? $fromPlanId;
    }

    /**
     * @return array{product_id: int, plan_id: string, title: string, price: float, period: ?string, plan_metadata: array<string, mixed>, dynamic_amount: bool}
     */
    public function resolveTargetPlanDetails(
        \Pimple\Container $di,
        Product $product,
        array $payload,
    ): array {
        $planId = (string) $payload['plan_id'];
        $planMeta = is_array($payload['plan_metadata'] ?? null) ? $payload['plan_metadata'] : [];
        $period = $this->resolvePeriodCode($product, $planId, $planMeta);
        $isDynamic = $this->isDynamicAmountProduct($product);
        $currencyCode = (string) ($payload['currency'] ?? 'IDR');
        $price = $isDynamic
            ? $this->resolveDynamicAmount($payload, $planMeta)
            : $this->computeProductPrice($di, $product, $currencyCode, $period);

        return [
            'product_id' => (int) $product->getId(),
            'plan_id' => $planId,
            'title' => (string) ($product->getTitle() ?? $planId),
            'price' => $price,
            'period' => $period,
            'plan_metadata' => $planMeta,
            'dynamic_amount' => $isDynamic,
        ];
    }

    public function isDynamicAmountProduct(Product $product): bool
    {
        $config = $product->getConfig() ?? [];

        return ($config['pricing_mode'] ?? CatalogItemSpec::PRICING_FIXED) === CatalogItemSpec::PRICING_DYNAMIC_AMOUNT;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $planMeta
     */
    private function resolveDynamicAmount(array $payload, array $planMeta): float
    {
        $raw = $planMeta['amount_idr'] ?? $planMeta['amount'] ?? $payload['amount_idr'] ?? $payload['amount'] ?? null;
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('Dynamic amount checkout requires a positive numeric amount.');
        }
        $amount = (float) $raw;
        if (!is_finite($amount) || $amount <= 0.0) {
            throw new \InvalidArgumentException('Dynamic amount checkout requires a finite amount greater than zero.');
        }

        return round($amount, 2);
    }

    /**
     * @param array{product_id: int, plan_id: string, title: string, price: float, period: ?string, plan_metadata: array<string, mixed>} $target
     * @param array<string, mixed> $orderConfig
     */
    public function applyCheckoutUpdateToOrder(
        \Pimple\Container $di,
        Order $order,
        array $target,
        array $orderConfig,
    ): void {
        $this->voidStaleUnpaidInvoice($di, $order, $target);

        if ($order->getStatus() === Order::STATUS_PENDING_SETUP) {
            $order->setProductId($target['product_id']);
            $order->setTitle($target['title']);
            $order->setPrice((string) $target['price']);
            if ($target['period'] !== null) {
                $order->setPeriod($target['period']);
            }
            $merged = array_merge($order->getConfig() ?? [], $orderConfig);
            unset($merged[self::PENDING_UPGRADE_KEY]);
            $order->setConfig($merged);
        } else {
            $existingConfig = $order->getConfig() ?? [];
            if (isset($orderConfig['return_redirect_url'])) {
                $existingConfig['return_redirect_url'] = $orderConfig['return_redirect_url'];
            }
            if (isset($orderConfig['external_user_id']) && $orderConfig['external_user_id'] !== '') {
                $existingConfig['external_user_id'] = $orderConfig['external_user_id'];
            }
            $existingConfig[self::PENDING_UPGRADE_KEY] = $target;
            $order->setConfig($existingConfig);
        }

        $order->setUpdatedAt(new \DateTime());
        $di['em']->persist($order);
        $di['em']->flush();
    }

    public function applyPendingUpgradeToDraftInvoice(
        \Pimple\Container $di,
        object $invoice,
        Order $order,
    ): void {
        $pending = ($order->getConfig() ?? [])[self::PENDING_UPGRADE_KEY] ?? null;
        if (!is_array($pending)) {
            return;
        }

        InvoiceCompat::applyPendingUpgrade($di, $invoice, ['order' => $order, 'pending' => $pending]);
    }

    /** @param array{plan_id: string, price: float, period: ?string} $target */
    private function voidStaleUnpaidInvoice(\Pimple\Container $di, Order $order, array $target): void
    {
        $unpaidId = $order->getUnpaidInvoiceId();
        if ($unpaidId === null) {
            return;
        }

        $invoice = InvoiceCompat::findById($di, (int) $unpaidId);
        if ($invoice === null || !InvoiceCompat::isUnpaid($invoice)) {
            $order->setUnpaidInvoiceId(null);

            return;
        }

        if ($this->unpaidInvoiceMatchesTarget($order, $invoice, $target)) {
            return;
        }

        InvoiceCompat::cancelUnpaid($di, $invoice, 'Superseded by plan change to ' . $target['plan_id']);

        $orderService = $di['mod_service']('order');
        if (method_exists($orderService, 'unsetUnpaidInvoice')) {
            $orderService->unsetUnpaidInvoice($order);
        } else {
            $order->setUnpaidInvoiceId(null);
        }
    }

    /** @param array{plan_id: string, price: float, period: ?string} $target */
    private function unpaidInvoiceMatchesTarget(Order $order, object $invoice, array $target): bool
    {
        $config = $order->getConfig() ?? [];
        $pending = $config[self::PENDING_UPGRADE_KEY] ?? null;
        if (is_array($pending)) {
            return ($pending['plan_id'] ?? '') === $target['plan_id']
                && abs((float) ($pending['price'] ?? 0) - $target['price']) < 0.01
                && ($pending['period'] ?? null) === $target['period'];
        }

        return ($config['plan_id'] ?? '') === $target['plan_id']
            && abs(InvoiceCompat::baseIncome($invoice) - $target['price']) < 0.01;
    }

    private function computeProductPrice(
        \Pimple\Container $di,
        Product $product,
        string $currencyCode,
        ?string $periodCode,
    ): float {
        $basePrice = $this->resolveBaseProductPrice($product, $periodCode);
        if ($basePrice === null) {
            $prodCfg = $product->getConfig() ?? [];

            return (float) ($prodCfg['price'] ?? 0.0);
        }

        try {
            $currencyService = $di['mod_service']('currency');
            if (method_exists($currencyService, 'getCurrencyRepository')) {
                $rate = $currencyService->getCurrencyRepository()->getRateByCode($currencyCode);
                if (is_numeric($rate) && (float) $rate > 0) {
                    return $basePrice * (float) $rate;
                }
            }
        } catch (\Throwable) {
            // Fall back to base price when currency service is unavailable in isolated tests.
        }

        return $basePrice;
    }

    private function resolveBaseProductPrice(Product $product, ?string $periodCode): ?float
    {
        $pp = $product->getProductPayment();
        if (!$pp instanceof ProductPayment) {
            return null;
        }

        if ($pp->getType() === ProductPayment::FREE) {
            return 0.0;
        }
        if ($pp->getType() === ProductPayment::ONCE) {
            return $pp->getOncePrice();
        }
        if ($pp->getType() === ProductPayment::RECURRENT && $periodCode !== null) {
            $periodEntity = $pp->getPeriod($periodCode);
            if ($periodEntity !== null && $periodEntity->isEnabled()) {
                return $periodEntity->getPrice();
            }
        }

        return null;
    }

    private function productSupportsPeriod(Product $product, string $periodCode): bool
    {
        $pp = $product->getProductPayment();
        if (!$pp instanceof ProductPayment || $pp->getType() !== ProductPayment::RECURRENT) {
            return true;
        }
        $periodEntity = $pp->getPeriod($periodCode);

        return $periodEntity !== null && $periodEntity->isEnabled();
    }

    private function findFirstEnabledPeriod(Product $product): ?string
    {
        $pp = $product->getProductPayment();
        if (!$pp instanceof ProductPayment || $pp->getType() !== ProductPayment::RECURRENT) {
            return null;
        }
        foreach ($pp->getPeriods() as $periodEntity) {
            if ($periodEntity->isEnabled()) {
                return $periodEntity->getCode();
            }
        }

        return null;
    }

    private function normalizePeriodCode(string $raw): ?string
    {
        $trimmed = strtolower(trim($raw));

        return match ($trimmed) {
            'once', 'one-time', 'onetime' => null,
            'month', 'monthly', '1m' => '1M',
            'year', 'yearly', 'annual', '1y' => '1Y',
            default => preg_match('/^\d+[DWMY]$/i', $trimmed) === 1 ? strtoupper($trimmed) : null,
        };
    }

    private function inferPeriodFromPlanId(string $planId): ?string
    {
        $lower = strtolower($planId);
        if (str_contains($lower, 'yearly') || str_contains($lower, 'annual')) {
            return '1Y';
        }
        if (str_contains($lower, 'monthly')) {
            return '1M';
        }

        return null;
    }
}
