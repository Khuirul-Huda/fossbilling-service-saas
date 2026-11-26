<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Product\Entity\Product;
use Box\Mod\Product\Entity\ProductCategory;
use Box\Mod\Product\Entity\ProductPayment;
use Box\Mod\Product\Entity\ProductPaymentPeriod;
use Box\Mod\Servicesaas\Client\AppConfig;

/**
 * Maps a CatalogItemSpec onto FOSSBilling Product and ProductPayment entities.
 */
final class CatalogProductMapper
{
    public const string STATUS_CREATED = 'created';
    public const string STATUS_UPDATED = 'updated';
    public const string STATUS_UNCHANGED = 'unchanged';

    /**
     * Applies a catalog item onto a new or existing Product and returns the mutation status.
     */
    public function applyToProduct(Product $product, CatalogItemSpec $item, CatalogApplyContext $context): string
    {
        $isNew = $product->getId() === null;
        $beforeSnapshot = $isNew ? [] : $this->snapshot($product);

        $payment = $product->getProductPayment() ?? new ProductPayment();
        $this->applyPayment($payment, $item);

        $product->setProductPayment($payment);
        $product->setProductCategory($context->category);
        $product->setTitle($item->title);
        $product->setDescription($item->description);
        $product->setType('saas');
        $product->setUnit($item->billingType === CatalogItemSpec::BILLING_ONCE ? 'unit' : 'month');
        $product->setSetup('after_payment');
        $product->setActive($item->active);
        $product->setStatus($item->active ? 'enabled' : 'disabled');
        $product->setConfig($this->buildMergedConfig($product, $item, $context->app));

        if ($isNew && trim((string) $product->getSlug()) === '') {
            $product->setSlug($this->generateSlug($context->app->appId, $item->planId));
        }

        if ($isNew) {
            return self::STATUS_CREATED;
        }

        return $beforeSnapshot === $this->snapshot($product) ? self::STATUS_UNCHANGED : self::STATUS_UPDATED;
    }

    private function applyPayment(ProductPayment $payment, CatalogItemSpec $item): void
    {
        $payment->setType($item->billingType);

        if ($item->billingType === CatalogItemSpec::BILLING_FREE) {
            $payment->setOncePrice(0.0);
            $payment->setOnceSetupPrice(0.0);

            return;
        }

        if ($item->billingType === CatalogItemSpec::BILLING_ONCE) {
            $firstPrice = array_values($item->prices)[0] ?? 0.0;
            $price = $item->prices['once'] ?? $firstPrice;
            $setup = $item->setupFees['once'] ?? 0.0;
            $payment->setOncePrice((float) $price);
            $payment->setOnceSetupPrice((float) $setup);

            return;
        }

        $this->syncRecurrentPeriods($payment, $item);
    }

    private function syncRecurrentPeriods(ProductPayment $payment, CatalogItemSpec $item): void
    {
        $allCodes = array_values(array_unique(array_merge($item->enabledPeriods, array_keys($item->prices))));
        $sortOrder = 0;

        foreach ($allCodes as $code) {
            if ($code === 'once') {
                continue;
            }
            $period = $payment->getPeriod($code) ?? (new ProductPaymentPeriod())->setCode($code);
            $enabled = in_array($code, $item->enabledPeriods, true);
            $price = $item->prices[$code] ?? $period->getPrice();
            $setup = $item->setupFees[$code] ?? 0.0;

            $period->setPrice($price)
                ->setSetupPrice($setup)
                ->setEnabled($enabled)
                ->setSortOrder($sortOrder++);
            $payment->addPeriod($period);
        }

        foreach ($payment->getPeriods() as $existingPeriod) {
            if (!in_array($existingPeriod->getCode(), $item->enabledPeriods, true)) {
                $existingPeriod->setEnabled(false);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMergedConfig(Product $product, CatalogItemSpec $item, AppConfig $app): array
    {
        $existing = $product->getConfig() ?? [];
        $fallbackPrice = $this->resolveFallbackPrice($item);
        $fallbackPeriod = $item->billingType === CatalogItemSpec::BILLING_RECURRENT
            ? ($item->enabledPeriods[0] ?? '1M')
            : null;

        $merged = array_merge($existing, [
            'app_id' => $app->appId,
            'plan_id' => $item->planId,
            'kind' => $item->kind,
            'pricing_mode' => $item->pricingMode,
            'currency' => $item->currency,
            'enabled_periods' => $item->enabledPeriods,
            'sync_managed' => true,
            'sync_source' => 'api',
            'plan_metadata' => array_merge($item->metadata, ['item_type' => $item->kind]),
            'price' => $fallbackPrice,
        ]);

        if ($fallbackPeriod !== null) {
            $merged['period'] = $fallbackPeriod;
        } else {
            unset($merged['period']);
        }

        return $merged;
    }

    private function resolveFallbackPrice(CatalogItemSpec $item): float
    {
        if ($item->billingType === CatalogItemSpec::BILLING_FREE) {
            return 0.0;
        }
        if ($item->billingType === CatalogItemSpec::BILLING_ONCE) {
            $firstPrice = array_values($item->prices)[0] ?? 0.0;

            return (float) ($item->prices['once'] ?? $firstPrice);
        }

        foreach ($item->enabledPeriods as $code) {
            if (isset($item->prices[$code])) {
                return (float) $item->prices[$code];
            }
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        $payment = $product->getProductPayment();
        $periods = [];
        if ($payment instanceof ProductPayment) {
            foreach ($payment->getPeriods() as $period) {
                $periods[$period->getCode()] = [
                    'price' => $period->getPrice(),
                    'setup' => $period->getSetupPrice(),
                    'enabled' => $period->isEnabled(),
                ];
            }
            ksort($periods);
        }

        return [
            'title' => $product->getTitle(),
            'description' => $product->getDescription(),
            'active' => $product->isActive(),
            'status' => $product->getStatus(),
            'category_id' => $product->getProductCategory()?->getId(),
            'payment_type' => $payment?->getType(),
            'once_price' => $payment?->getOncePrice(),
            'periods' => $periods,
            'config' => $product->getConfig() ?? [],
        ];
    }

    private function generateSlug(string $appId, string $planId): string
    {
        $raw = strtolower(sprintf('saas-%s-%s', $appId, $planId));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $raw) ?? 'saas-product';
        $trimmed = trim($slug, '-');

        return $trimmed . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
}

/**
 * Parameter object grouping target category and app configuration for catalog mapping.
 */
final readonly class CatalogApplyContext
{
    public function __construct(
        public AppConfig $app,
        public ProductCategory $category,
    ) {
    }
}
