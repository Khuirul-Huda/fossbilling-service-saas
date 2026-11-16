<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Client\ExpirationCalculator;

it('calculates activation timestamps for 1D, 1W, 1M, 1Y and defaults empty period to 1M', function (
    ?string $periodCode,
    string $expectedShift,
): void {
    // Arrange
    $calc = new ExpirationCalculator();
    $base = strtotime('2026-03-15 10:00:00 UTC');
    expect($base)->toBeInt();

    // Act
    $expiresAt = $calc->calculateForActivation($periodCode, $base);

    // Assert
    expect($expiresAt)->toBe(strtotime($expectedShift, $base))
        ->and(ExpirationCalculator::toMicroseconds($expiresAt))->toBe($expiresAt * 1_000_000);
})->with([
    '1 day' => ['1D', '+1 days'],
    '1 week' => ['1W', '+1 weeks'],
    '1 month' => ['1M', '+1 months'],
    '1 year' => ['1Y', '+1 years'],
    'empty string fallback to 1M' => ['', '+1 months'],
    'null fallback to 1M' => [null, '+1 months'],
]);

it('rejects malformed or non-positive billing period codes', function (string $invalidCode): void {
    // Arrange
    $calc = new ExpirationCalculator();

    // Act & Assert
    expect(fn (): int => $calc->calculateForActivation($invalidCode, 1_750_000_000))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'zero quantity' => ['0M'],
    'unsupported unit' => ['1H'],
    'arbitrary string' => ['monthly'],
]);

it('calculates renewals using from_today, from_greater, and current expiry rules', function (): void {
    // Arrange
    $calc = new ExpirationCalculator();
    $now = time();
    $futureExpiry = strtotime('+10 days', $now);
    $pastExpiry = strtotime('-10 days', $now);

    // Act
    $fromToday = $calc->calculateForRenewal('1M', $futureExpiry, ExpirationCalculator::LOGIC_FROM_TODAY);
    $fromGreaterFuture = $calc->calculateForRenewal('1M', $futureExpiry, ExpirationCalculator::LOGIC_FROM_GREATER);
    $fromGreaterPast = $calc->calculateForRenewal('1M', $pastExpiry, ExpirationCalculator::LOGIC_FROM_GREATER);
    $fromCurrent = $calc->calculateForRenewal('1M', $pastExpiry, 'from_current');

    // Assert
    $expectedToday = strtotime('+1 months', $now);
    expect(abs($fromToday - $expectedToday))->toBeLessThanOrEqual(2)
        ->and($fromGreaterFuture)->toBe(strtotime('+1 months', $futureExpiry))
        ->and(abs($fromGreaterPast - $expectedToday))->toBeLessThanOrEqual(2)
        ->and($fromCurrent)->toBe(strtotime('+1 months', $pastExpiry));
});
