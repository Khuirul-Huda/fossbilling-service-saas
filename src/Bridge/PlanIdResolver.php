<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

/**
 * Resolves single-tier (multi-period) and per-period SaaS plan identifiers.
 */
final class PlanIdResolver
{
    /**
     * @param array<string, mixed> $productConfig
     */
    public static function matchesProductExact(array $productConfig, string $requestedPlanId): bool
    {
        $target = trim($requestedPlanId);
        if ($target === '') {
            return false;
        }

        if (trim((string) ($productConfig['plan_id'] ?? '')) === $target) {
            return true;
        }

        $planIds = $productConfig['plan_ids'] ?? null;
        if (!is_array($planIds)) {
            return false;
        }

        foreach ($planIds as $periodPlanId) {
            if (is_string($periodPlanId) && trim($periodPlanId) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $productConfig
     */
    public static function matchesProductFlexible(array $productConfig, string $requestedPlanId): bool
    {
        if (self::matchesProductExact($productConfig, $requestedPlanId)) {
            return true;
        }

        $configured = trim((string) ($productConfig['plan_id'] ?? ''));
        $target = trim($requestedPlanId);
        if ($configured === '' || $target === '') {
            return false;
        }

        if (str_contains($configured, '{period}')) {
            return str_replace('{period}', 'monthly', $configured) === $target
                || str_replace('{period}', 'yearly', $configured) === $target;
        }

        $configuredBase = self::stripCycleToken($configured);
        $targetBase = self::stripCycleToken($target);

        return $configuredBase !== '' && $configuredBase === $targetBase;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function resolveForPeriod(array $config, ?string $period): string
    {
        $normPeriod = strtoupper(trim((string) $period));
        $planIds = $config['plan_ids'] ?? null;
        if ($normPeriod !== '' && is_array($planIds)) {
            $mapped = trim((string) ($planIds[$normPeriod] ?? ''));
            if ($mapped !== '') {
                return $mapped;
            }
        }

        $rawPlanId = trim((string) ($config['plan_id'] ?? ''));
        if ($rawPlanId === '' || $normPeriod === '') {
            return $rawPlanId;
        }

        $cycleWord = self::periodToCycleWord($normPeriod);
        if ($cycleWord === null) {
            return $rawPlanId;
        }

        if (str_contains($rawPlanId, '{period}')) {
            return str_replace('{period}', $cycleWord, $rawPlanId);
        }

        return self::rewriteCycleToken($rawPlanId, $cycleWord);
    }

    private static function periodToCycleWord(string $normPeriod): ?string
    {
        return match ($normPeriod) {
            '1M' => 'monthly',
            '1Y' => 'yearly',
            default => null,
        };
    }

    private static function rewriteCycleToken(string $planId, string $cycleWord): string
    {
        $replaced = preg_replace('/(?<=_)(monthly|yearly)(?=_|$)/i', $cycleWord, $planId, 1, $count);

        return is_string($replaced) && $count > 0 ? $replaced : $planId;
    }

    private static function stripCycleToken(string $planId): string
    {
        $stripped = preg_replace('/_(monthly|yearly)(?=_|$)/i', '', $planId, 1);

        return is_string($stripped) ? strtolower($stripped) : strtolower($planId);
    }
}
