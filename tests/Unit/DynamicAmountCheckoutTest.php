<?php

declare(strict_types=1);

use Box\Mod\Product\Entity\Product;
use Box\Mod\Product\Entity\ProductPayment;
use Box\Mod\Product\Entity\ProductPaymentPeriod;
use Box\Mod\Servicesaas\Bridge\CheckoutOrderHelper;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;

it('resolves fixed multi-period prices and encrypted dynamic_amount prices accurately', function (): void {
    $harness = new DoctrineBillingHarness('http://remote-app.test');
    $helper = new CheckoutOrderHelper();

    $fixedProduct = (new Product())->setTitle('Pro Seller')->setType('saas')->setConfig([
        'app_id' => 'sebarke',
        'plan_id' => 'laris',
        'pricing_mode' => 'fixed',
    ]);
    $pp = (new ProductPayment())->setType(ProductPayment::RECURRENT);
    $pp->addPeriod((new ProductPaymentPeriod())->setCode('1M')->setPrice(129000)->setEnabled(true));
    $pp->addPeriod((new ProductPaymentPeriod())->setCode('1Y')->setPrice(1290000)->setEnabled(true));
    $fixedProduct->setProductPayment($pp);
    $harness->em->persist($pp);
    $harness->em->persist($fixedProduct);

    $topupProduct = (new Product())->setTitle('Top-Up Saldo')->setType('saas')->setConfig([
        'app_id' => 'sebarke',
        'plan_id' => 'topup_saldo',
        'kind' => 'topup',
        'pricing_mode' => 'dynamic_amount',
    ]);
    $topupPay = (new ProductPayment())->setType(ProductPayment::ONCE)->setOncePrice(0);
    $topupProduct->setProductPayment($topupPay);
    $harness->em->persist($topupPay);
    $harness->em->persist($topupProduct);
    $harness->em->flush();

    $monthly = $helper->resolveTargetPlanDetails($harness->di, $fixedProduct, [
        'plan_id' => 'laris',
        'plan_metadata' => ['period' => '1M'],
    ]);
    $yearly = $helper->resolveTargetPlanDetails($harness->di, $fixedProduct, [
        'plan_id' => 'laris',
        'plan_metadata' => ['period' => '1Y'],
    ]);
    $dynamicTopup = $helper->resolveTargetPlanDetails($harness->di, $topupProduct, [
        'plan_id' => 'topup_saldo',
        'plan_metadata' => ['item_type' => 'topup', 'amount_idr' => 75000, 'period' => 'once'],
    ]);

    expect($monthly['price'])->toBe(129000.0)
        ->and($monthly['period'])->toBe('1M')
        ->and($yearly['price'])->toBe(1290000.0)
        ->and($yearly['period'])->toBe('1Y')
        ->and($dynamicTopup['dynamic_amount'])->toBeTrue()
        ->and($dynamicTopup['price'])->toBe(75000.0)
        ->and($dynamicTopup['period'])->toBeNull();
});

it('rejects non-positive dynamic_amount checkout amounts', function (): void {
    $harness = new DoctrineBillingHarness('http://remote-app.test');
    $helper = new CheckoutOrderHelper();

    $topupProduct = (new Product())->setTitle('Overage Settlement')->setType('saas')->setConfig([
        'app_id' => 'sebarke',
        'plan_id' => 'overage',
        'kind' => 'overage',
        'pricing_mode' => 'dynamic_amount',
    ]);
    $harness->em->persist($topupProduct);
    $harness->em->flush();

    expect(static fn () => $helper->resolveTargetPlanDetails($harness->di, $topupProduct, [
        'plan_id' => 'overage',
        'plan_metadata' => ['amount_idr' => -5000],
    ]))->toThrow(\InvalidArgumentException::class);
});
