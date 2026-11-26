<?php

declare(strict_types=1);

use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Box\Mod\Servicesaas\Api\Guest;
use Box\Mod\Servicesaas\Bridge\CatalogSyncBridge;
use Box\Mod\Servicesaas\Bridge\CatalogSyncRequest;
use Box\Mod\Servicesaas\Client\AppConfig;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

it('upserts multi-period plans, addons, and dynamic items idempotently via sync_catalog while keeping manual legacy products untouched', function (): void {
    $harness = new DoctrineBillingHarness('http://remote-app.test');
    $channel = EncryptedChannel::persistent();

    // Seed a manual legacy SaaS product for the same app that must NOT be archived.
    $legacyManual = $harness->seedSaasProduct('legacy_manual_vip', 99000.0, '1M');
    $legacyConfig = $legacyManual->getConfig();
    $legacyConfig['app_id'] = 'cloudapp';
    $legacyManual->setConfig($legacyConfig);
    $harness->em->flush();

    $guest = new Guest();
    $guest->setDi($harness->di);
    $guest->setService($harness->saasService);

    $catalogPayload = [
        'app_id' => 'cloudapp',
        'category_title' => 'CloudApp Synced Tiers',
        'mode' => 'upsert_and_archive',
        'plans' => [
            [
                'plan_id' => 'starter',
                'title' => 'Starter Cloud',
                'kind' => 'plan',
                'pricing_mode' => 'fixed',
                'billing_type' => 'recurrent',
                'prices' => ['1M' => 49000, '1Y' => 490000],
                'enabled_periods' => ['1M', '1Y'],
                'active' => true,
            ],
            [
                'plan_id' => 'booster_25h',
                'title' => 'Booster 25 Jam',
                'kind' => 'addon',
                'pricing_mode' => 'fixed',
                'billing_type' => 'once',
                'prices' => ['once' => 25000],
                'enabled_periods' => ['once'],
                'active' => true,
            ],
            [
                'plan_id' => 'topup_saldo',
                'title' => 'Top-Up Saldo',
                'kind' => 'topup',
                'pricing_mode' => 'dynamic_amount',
                'billing_type' => 'once',
                'prices' => ['once' => 0],
                'enabled_periods' => ['once'],
                'active' => true,
            ],
        ],
    ];

    $res1 = $channel->open(
        \Box\Mod\Servicesaas\Crypto\EnvelopeDto::fromArray(
            $guest->sync_catalog($channel->seal('cloudapp', $catalogPayload, SaasTestContext::TEST_SECRET)->toArray()),
        ),
        SaasTestContext::TEST_SECRET,
    );

    expect($res1['created'])->toBe(3)
        ->and($res1['updated'])->toBe(0)
        ->and($res1['archived'])->toBe(0);

    // Repeat identical payload -> unchanged=3, no duplicate products.
    $res2 = $channel->open(
        \Box\Mod\Servicesaas\Crypto\EnvelopeDto::fromArray(
            $guest->sync_catalog($channel->seal('cloudapp', $catalogPayload, SaasTestContext::TEST_SECRET)->toArray()),
        ),
        SaasTestContext::TEST_SECRET,
    );
    expect($res2['unchanged'])->toBe(3)->and($res2['created'])->toBe(0);

    // Verify legacy manual product remains active and untouched.
    $harness->em->refresh($legacyManual);
    expect($legacyManual->isActive())->toBeTrue();

    // Update monthly price & disable annual period on 'starter', remove booster -> archived=1.
    $updatedPayload = $catalogPayload;
    $updatedPayload['plans'][0]['prices'] = ['1M' => 59000, '1Y' => 490000];
    $updatedPayload['plans'][0]['enabled_periods'] = ['1M'];
    unset($updatedPayload['plans'][1]);
    $updatedPayload['plans'] = array_values($updatedPayload['plans']);

    $res3 = $channel->open(
        \Box\Mod\Servicesaas\Crypto\EnvelopeDto::fromArray(
            $guest->sync_catalog($channel->seal('cloudapp', $updatedPayload, SaasTestContext::TEST_SECRET)->toArray()),
        ),
        SaasTestContext::TEST_SECRET,
    );

    expect($res3['updated'])->toBe(1)
        ->and($res3['unchanged'])->toBe(1)
        ->and($res3['archived'])->toBe(1);

    // Checkout using synced dynamic_amount topup + synced starter plan does not collide on subscription order.
    $starterCheckout = $channel->open(
        \Box\Mod\Servicesaas\Crypto\EnvelopeDto::fromArray(
            $guest->checkout_session($channel->seal('cloudapp', [
                'app_id' => 'cloudapp',
                'external_user_id' => 'usr_sync_1',
                'email' => 'syncer@cloudapp.test',
                'plan_id' => 'starter',
                'action' => 'buy',
                'redirect_url' => 'https://app.cloudapp.test/billing',
                'plan_metadata' => ['item_type' => 'plan', 'period' => '1M'],
            ], SaasTestContext::TEST_SECRET)->toArray()),
        ),
        SaasTestContext::TEST_SECRET,
    );

    $topupCheckout = $channel->open(
        \Box\Mod\Servicesaas\Crypto\EnvelopeDto::fromArray(
            $guest->checkout_session($channel->seal('cloudapp', [
                'app_id' => 'cloudapp',
                'external_user_id' => 'usr_sync_1',
                'email' => 'syncer@cloudapp.test',
                'plan_id' => 'topup_saldo',
                'action' => 'buy',
                'redirect_url' => 'https://app.cloudapp.test/billing',
                'plan_metadata' => ['item_type' => 'topup', 'amount_idr' => 125000, 'period' => 'once'],
            ], SaasTestContext::TEST_SECRET)->toArray()),
        ),
        SaasTestContext::TEST_SECRET,
    );

    $starterOrder = $harness->em->getRepository(Order::class)->find($starterCheckout['order_id']);
    $topupOrder = $harness->em->getRepository(Order::class)->find($topupCheckout['order_id']);

    expect($starterOrder?->getPrice())->toBe('59000.00')
        ->and($topupCheckout['order_id'])->not->toBe($starterCheckout['order_id'])
        ->and($topupOrder?->getPrice())->toBe('125000.00');
});

it('rejects catalog sync when allow_catalog_sync is disabled on AppConfig', function (): void {
    $harness = new DoctrineBillingHarness('http://remote-app.test');
    $bridge = new CatalogSyncBridge(EncryptedChannel::persistent(), $harness->saasService->getAppRegistry(), $harness->em);

    $disabledApp = AppConfig::fromArray([
        'app_id' => 'lockedapp',
        'name' => 'Locked',
        'base_url' => 'http://locked.test',
        'shared_secret' => SaasTestContext::TEST_SECRET,
        'allow_catalog_sync' => false,
    ]);
    $req = CatalogSyncRequest::fromDecryptedPayload([
        'app_id' => 'lockedapp',
        'plans' => [['plan_id' => 'basic', 'title' => 'Basic']],
    ]);

    expect(static fn () => $bridge->sync($disabledApp, $req))->toThrow(\RuntimeException::class);
});
