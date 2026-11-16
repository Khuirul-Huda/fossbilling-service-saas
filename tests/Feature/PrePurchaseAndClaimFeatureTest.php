<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Bridge\EntitlementClaimer;
use Box\Mod\Servicesaas\Client\ExpirationCalculator;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;
use Box\Mod\Servicesaas\Tests\Support\FakeTransportRecorder;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

it('keeps pre-purchased record in pending_claim when remote app returns pending_registration', function (): void {
    // Arrange
    $recorder = new FakeTransportRecorder(
        SaasTestContext::TEST_SECRET,
        static fn (array $payload): array => [
            'status' => 'pending_registration',
            'order_id' => $payload['order_id'],
        ],
    );
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test', null, $recorder->asClosure());
    $client = $harness->seedClient('prebuyer@example.com');
    $product = $harness->seedSaasProduct('200gb_monthly', 150000.0, '1M');
    $orderId = $harness->di['mod_service']('order')->createOrder($client, $product, [
        'config' => [
            'app_id' => 'cloudapp',
            'plan_id' => '200gb_monthly',
            'target_email' => 'PreBuyer@Example.com',
            'plan_metadata' => ['storage_bytes' => 214_748_364_800, 'period' => '1M'],
        ],
    ]);
    $order = $harness->em->getRepository(Order::class)->find($orderId);
    expect($order)->toBeInstanceOf(Order::class);

    // Act
    $activated = $harness->saasService->activate($order);

    // Assert
    $record = $harness->saasRepo->findByOrderId($orderId);
    expect($activated)->toBeTrue()
        ->and($recorder->requests)->toHaveCount(1)
        ->and($recorder->requests[0]['payload']['action'])->toBe('activate')
        ->and($record?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PENDING_CLAIM)
        ->and($record?->externalUserId)->toBeNull();
});

it('claims pre-purchased entitlement case-insensitively, binds external_user_id, and resets order expiry', function (): void {
    // Arrange
    $recorder = new FakeTransportRecorder(
        SaasTestContext::TEST_SECRET,
        static fn (): array => ['status' => 'pending_registration'],
    );
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test', null, $recorder->asClosure());
    $client = $harness->seedClient('buyer@example.com');
    $product = $harness->seedSaasProduct('200gb_monthly', 150000.0, '1M');
    $orderId = $harness->di['mod_service']('order')->createOrder($client, $product, [
        'config' => [
            'app_id' => 'cloudapp',
            'plan_id' => '200gb_monthly',
            'target_email' => 'buyer@example.com',
            'plan_metadata' => ['storage_bytes' => 214_748_364_800, 'period' => '1M'],
        ],
    ]);
    $order = $harness->em->getRepository(Order::class)->find($orderId);
    expect($order)->toBeInstanceOf(Order::class);
    $order->setExpiresAt((new \DateTime())->setTimestamp(time() - 86400));
    $harness->em->flush();

    $harness->saasService->activate($order);

    $claimer = (new EntitlementClaimer(
        new EncryptedChannel(),
        SaasTestContext::createRegistry('https://api.cloudapp.test'),
        $harness->saasRepo,
    ))->setDi($harness->di);

    $claimEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'email' => 'BUYER@EXAMPLE.COM',
        'external_user_id' => '90077',
    ]);

    // Act
    $claimResponse = SaasTestContext::open($claimer->handleEncryptedClaim($claimEnvelope));

    // Assert
    $updatedRecord = $harness->saasRepo->findByOrderId($orderId);
    $harness->em->refresh($order);
    $expectedMinExpiry = ExpirationCalculator::toMicroseconds(strtotime('+25 days'));

    expect($claimResponse['matched'])->toBeTrue()
        ->and($claimResponse['order_id'])->toBe($orderId)
        ->and($claimResponse['plan_id'])->toBe('200gb_monthly')
        ->and($claimResponse['plan_metadata']['storage_bytes'])->toBe(214_748_364_800)
        ->and($claimResponse['expires_at_micro'])->toBeGreaterThan($expectedMinExpiry)
        ->and($updatedRecord?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED)
        ->and($updatedRecord?->externalUserId)->toBe('90077')
        ->and($order->getExpiresAt()?->getTimestamp())->toBe((int) ($claimResponse['expires_at_micro'] / 1_000_000));
});

it('returns matched=false for unknown emails and canceled service_saas records', function (): void {
    // Arrange
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test');
    $harness->saasRepo->insert(new ServiceSaasRecord([
        'client_id' => 10,
        'order_id' => 555,
        'app_id' => 'cloudapp',
        'plan_id' => '200gb_monthly',
        'target_email' => 'canceled@example.com',
        'provisioning_status' => ServiceSaasRecord::STATUS_CANCELED,
    ]));

    $claimer = new EntitlementClaimer(
        new EncryptedChannel(),
        SaasTestContext::createRegistry('https://api.cloudapp.test'),
        $harness->saasRepo,
    );

    $unknownClaim = SaasTestContext::seal('cloudapp', ['email' => 'nobody@example.com', 'external_user_id' => '1']);
    $canceledClaim = SaasTestContext::seal('cloudapp', ['email' => 'canceled@example.com', 'external_user_id' => '2']);

    // Act
    $unknownResp = SaasTestContext::open($claimer->handleEncryptedClaim($unknownClaim));
    $canceledResp = SaasTestContext::open($claimer->handleEncryptedClaim($canceledClaim));

    // Assert
    expect($unknownResp['matched'])->toBeFalse()
        ->and($canceledResp['matched'])->toBeFalse();
});
