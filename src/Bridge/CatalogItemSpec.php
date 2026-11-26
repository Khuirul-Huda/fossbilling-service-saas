<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

/**
 * Normalized catalog entry describing a subscription tier, add-on, or dynamic billing item.
 */
final readonly class CatalogItemSpec
{
    public const string KIND_PLAN = 'plan';
    public const string KIND_ADDON = 'addon';
    public const string KIND_TOPUP = 'topup';
    public const string KIND_OVERAGE = 'overage';

    public const string PRICING_FIXED = 'fixed';
    public const string PRICING_DYNAMIC_AMOUNT = 'dynamic_amount';

    public const string BILLING_RECURRENT = 'recurrent';
    public const string BILLING_ONCE = 'once';
    public const string BILLING_FREE = 'free';

    private const array ALLOWED_KINDS = [
        self::KIND_PLAN,
        self::KIND_ADDON,
        self::KIND_TOPUP,
        self::KIND_OVERAGE,
    ];

    private const array ALLOWED_PRICING_MODES = [
        self::PRICING_FIXED,
        self::PRICING_DYNAMIC_AMOUNT,
    ];

    private const array ALLOWED_BILLING_TYPES = [
        self::BILLING_RECURRENT,
        self::BILLING_ONCE,
        self::BILLING_FREE,
    ];

    public string $planId;
    public string $title;
    public string $description;
    public string $kind;
    public string $pricingMode;
    public string $billingType;
    public string $currency;
    /** @var array<string, float> */
    public array $prices;
    /** @var list<string> */
    public array $enabledPeriods;
    /** @var array<string, float> */
    public array $setupFees;
    public bool $active;
    /** @var array<string, mixed> */
    public array $metadata;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        $this->planId = $data['plan_id'];
        $this->title = $data['title'];
        $this->description = $data['description'];
        $this->kind = $data['kind'];
        $this->pricingMode = $data['pricing_mode'];
        $this->billingType = $data['billing_type'];
        $this->currency = $data['currency'];
        $this->prices = $data['prices'];
        $this->enabledPeriods = $data['enabled_periods'];
        $this->setupFees = $data['setup_fees'];
        $this->active = $data['active'];
        $this->metadata = $data['metadata'];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $planId = trim((string) ($raw['plan_id'] ?? $raw['planId'] ?? ''));
        if ($planId === '') {
            throw new \InvalidArgumentException('Catalog item requires a non-empty plan_id.');
        }

        $title = trim((string) ($raw['title'] ?? $planId));
        $kind = strtolower(trim((string) ($raw['kind'] ?? self::KIND_PLAN)));
        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported catalog item kind "%s".', $kind));
        }

        $pricingMode = strtolower(trim((string) ($raw['pricing_mode'] ?? self::PRICING_FIXED)));
        if (!in_array($pricingMode, self::ALLOWED_PRICING_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported pricing_mode "%s".', $pricingMode));
        }

        $billingType = self::resolveBillingType($raw, $pricingMode);
        $prices = self::normalizePrices($raw['prices'] ?? []);
        $enabledPeriods = self::resolveEnabledPeriods($raw['enabled_periods'] ?? null, $prices, $billingType);

        return new self([
            'plan_id' => $planId,
            'title' => $title !== '' ? $title : $planId,
            'description' => trim((string) ($raw['description'] ?? '')),
            'kind' => $kind,
            'pricing_mode' => $pricingMode,
            'billing_type' => $billingType,
            'currency' => strtoupper(trim((string) ($raw['currency'] ?? 'IDR'))) ?: 'IDR',
            'prices' => $prices,
            'enabled_periods' => $enabledPeriods,
            'setup_fees' => self::normalizePrices($raw['setup_fees'] ?? []),
            'active' => array_key_exists('active', $raw) ? (bool) $raw['active'] : true,
            'metadata' => is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
        ]);
    }

    public function isDynamicAmount(): bool
    {
        return $this->pricingMode === self::PRICING_DYNAMIC_AMOUNT;
    }

    public function isStandaloneOrder(): bool
    {
        return $this->kind !== self::KIND_PLAN;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function resolveBillingType(array $raw, string $pricingMode): string
    {
        $rawType = strtolower(trim((string) ($raw['billing_type'] ?? '')));
        if ($rawType === '') {
            return $pricingMode === self::PRICING_DYNAMIC_AMOUNT ? self::BILLING_ONCE : self::BILLING_RECURRENT;
        }
        if (!in_array($rawType, self::ALLOWED_BILLING_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported billing_type "%s".', $rawType));
        }

        return $rawType;
    }

    /**
     * @return array<string, float>
     */
    private static function normalizePrices(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $period => $amount) {
            $key = self::normalizePeriodKey((string) $period);
            if ($key === '' || !is_numeric($amount)) {
                continue;
            }
            $value = (float) $amount;
            if (!is_finite($value) || $value < 0) {
                throw new \InvalidArgumentException(sprintf('Catalog price for "%s" must be non-negative.', $key));
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, float> $prices
     *
     * @return list<string>
     */
    private static function resolveEnabledPeriods(mixed $rawEnabled, array $prices, string $billingType): array
    {
        if ($billingType === self::BILLING_FREE) {
            return [];
        }
        if ($billingType === self::BILLING_ONCE) {
            return ['once'];
        }
        if (!is_array($rawEnabled)) {
            $codes = array_values(array_filter(
                array_keys($prices),
                static fn (string $code): bool => $code !== 'once',
            ));

            return $codes !== [] ? $codes : ['1M'];
        }

        $periods = [];
        foreach ($rawEnabled as $period) {
            $key = self::normalizePeriodKey((string) $period);
            if ($key !== '' && $key !== 'once' && !in_array($key, $periods, true)) {
                $periods[] = $key;
            }
        }

        return $periods;
    }

    private static function normalizePeriodKey(string $raw): string
    {
        $trimmed = strtolower(trim($raw));

        return match ($trimmed) {
            'once', 'one-time', 'onetime' => 'once',
            'month', 'monthly', '1m' => '1M',
            'year', 'yearly', 'annual', '1y' => '1Y',
            default => preg_match('/^\d+[dwmy]$/i', $trimmed) === 1 ? strtoupper($trimmed) : '',
        };
    }
}
