<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Client;

final class ExpirationCalculator
{
    public const string DEFAULT_PERIOD_CODE = '1M';
    public const string LOGIC_FROM_TODAY = 'from_today';
    public const string LOGIC_FROM_GREATER = 'from_greater';
    public const int MICROSECONDS_PER_SECOND = 1_000_000;

    private const array UNIT_SHIFTS = [
        'D' => 'days',
        'W' => 'weeks',
        'M' => 'months',
        'Y' => 'years',
    ];

    public function calculateForActivation(?string $periodCode, ?int $now = null): int
    {
        $baseTimestamp = $now ?? time();

        return $this->addPeriod($periodCode, $baseTimestamp);
    }

    public function calculateForRenewal(
        ?string $periodCode,
        ?int $currentExpiresAt,
        string $renewalLogic = self::LOGIC_FROM_GREATER,
    ): int {
        $now = time();
        $baseTimestamp = $this->resolveRenewalBaseTimestamp($currentExpiresAt, $renewalLogic, $now);

        return $this->addPeriod($periodCode, $baseTimestamp);
    }

    public static function toMicroseconds(int $unixSeconds): int
    {
        return $unixSeconds * self::MICROSECONDS_PER_SECOND;
    }

    private function resolveRenewalBaseTimestamp(?int $currentExpiresAt, string $renewalLogic, int $now): int
    {
        if ($renewalLogic === self::LOGIC_FROM_TODAY || $currentExpiresAt === null) {
            return $now;
        }

        if ($renewalLogic === self::LOGIC_FROM_GREATER) {
            return max($currentExpiresAt, $now);
        }

        return $currentExpiresAt;
    }

    private function addPeriod(?string $periodCode, int $baseTimestamp): int
    {
        $normalized = strtoupper(trim((string) $periodCode));
        if ($normalized === '') {
            $normalized = self::DEFAULT_PERIOD_CODE;
        }

        if (preg_match('/^(\d+)([DWMY])$/', $normalized, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid billing period code "%s".', (string) $periodCode));
        }

        $quantity = (int) $matches[1];
        $unit = $matches[2];
        $shiftUnit = self::UNIT_SHIFTS[$unit];

        if ($quantity <= 0) {
            throw new \InvalidArgumentException(sprintf('Unsupported billing period "%s".', (string) $periodCode));
        }

        $calculated = strtotime(sprintf('+%d %s', $quantity, $shiftUnit), $baseTimestamp);
        if ($calculated === false) {
            throw new \RuntimeException('Failed to calculate expiration timestamp.');
        }

        return $calculated;
    }
}
