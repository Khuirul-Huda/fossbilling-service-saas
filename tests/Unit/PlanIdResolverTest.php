<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Bridge\PlanIdResolver;

it('matches exact, per-period map, template, and sibling cycle product configurations', function (): void {
    $mapCfg = ['plan_id' => '20gb_{period}_v4', 'plan_ids' => ['1M' => '20gb_monthly_v4', '1Y' => '20gb_yearly_v4']];
    $siblingCfg = ['plan_id' => '100gb_monthly_v4'];
    $baseCfg = ['plan_id' => '500gb_v4'];
    $customCfg = ['plan_id' => 'ProPlan'];

    expect(PlanIdResolver::matchesProductExact($mapCfg, '20gb_yearly_v4'))->toBeTrue()
        ->and(PlanIdResolver::matchesProductExact($mapCfg, '20gb_monthly_v4'))->toBeTrue()
        ->and(PlanIdResolver::matchesProductFlexible(['plan_id' => '20gb_{period}_v4'], '20gb_yearly_v4'))->toBeTrue()
        ->and(PlanIdResolver::matchesProductFlexible($siblingCfg, '100gb_yearly_v4'))->toBeTrue()
        ->and(PlanIdResolver::matchesProductFlexible($baseCfg, '500gb_yearly_v4'))->toBeTrue()
        ->and(PlanIdResolver::matchesProductFlexible($customCfg, '20gb_yearly_v4'))->toBeFalse();
});

it('resolves outbound plan_id based on billing period while leaving custom plans untouched', function (): void {
    $mapCfg = ['plan_id' => '20gb_{period}_v4', 'plan_ids' => ['1M' => '20gb_monthly_v4', '1Y' => '20gb_yearly_v4']];
    $templateCfg = ['plan_id' => '100gb_{period}_v4'];
    $siblingCfg = ['plan_id' => '500gb_monthly_v4'];
    $customCfg = ['plan_id' => 'ProPlan'];

    expect(PlanIdResolver::resolveForPeriod($mapCfg, '1Y'))->toBe('20gb_yearly_v4')
        ->and(PlanIdResolver::resolveForPeriod($mapCfg, '1M'))->toBe('20gb_monthly_v4')
        ->and(PlanIdResolver::resolveForPeriod($templateCfg, '1Y'))->toBe('100gb_yearly_v4')
        ->and(PlanIdResolver::resolveForPeriod($templateCfg, '1M'))->toBe('100gb_monthly_v4')
        ->and(PlanIdResolver::resolveForPeriod($siblingCfg, '1Y'))->toBe('500gb_yearly_v4')
        ->and(PlanIdResolver::resolveForPeriod($siblingCfg, '1M'))->toBe('500gb_monthly_v4')
        ->and(PlanIdResolver::resolveForPeriod($customCfg, '1Y'))->toBe('ProPlan');
});
