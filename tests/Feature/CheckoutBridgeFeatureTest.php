<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Client\Entity\Client;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Bridge\CheckoutBridge;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;
use Box\Mod\Servicesaas\Tests\Support\FakeTransportRecorder;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

it('creates client, order, invoice, and saas record on encrypted action=buy checkout', function (): void {
    // Arrange
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test');
    $harness->seedSaasProduct('200gb_monthly', 150000.0);
    $bridge = (new CheckoutBridge(
        new EncryptedChannel(),
        SaasTestContext::createRegistry('https://api.cloudapp.test'),
        $harness->saasRepo,
    ))->setDi($harness->di);

    $envelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'buy',
        'email' => 'Alice@Example.com',
        'external_user_id' => '1001',
        'plan_id' => '200gb_monthly',
        'redirect_url' => 'https://app.cloudapp.test/billing/done',
        'plan_metadata' => ['storage_bytes' => 214_748_364_800],
    ]);

    // Act
    $responseEnvelope = $bridge->handleEncryptedRequest(
        $envelope,
        null,
        static fn (): string => 'https://billing.example.test',
    );
    $response = SaasTestContext::open($responseEnvelope);

    // Assert
    expect($response['status'])->toBe('ok')
        ->and($response['order_id'])->toBeInt()->toBeGreaterThan(0)
        ->and($response['invoice_hash'])->toBeString()->not->toBeEmpty()
        ->and($response['checkout_url'])->toBe('https://billing.example.test/invoice/' . $response['invoice_hash']);

    $record = $harness->saasRepo->findByOrderId((int) $response['order_id']);
    expect($record)->not->toBeNull()
        ->and($record?->targetEmail)->toBe('alice@example.com')
        ->and($record?->externalUserId)->toBe('1001')
        ->and($record?->planId)->toBe('200gb_monthly')
        ->and($record?->returnRedirectUrl)->toBe('https://app.cloudapp.test/billing/done');

    $clients = $harness->em->getRepository(Client::class)->findAll();
    expect($clients)->toHaveCount(1);
});

it('reuses existing active service_saas order on encrypted action=update without duplicating orders', function (): void {
    // Arrange
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test');
    $harness->seedSaasProduct('50gb_monthly', 50000.0);
    $harness->seedSaasProduct('200gb_monthly', 150000.0);
    $registry = SaasTestContext::createRegistry('https://api.cloudapp.test');
    $bridge = (new CheckoutBridge(new EncryptedChannel(), $registry, $harness->saasRepo))->setDi($harness->di);

    $buyEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'buy',
        'email' => 'bob@example.com',
        'external_user_id' => '2002',
        'plan_id' => '50gb_monthly',
        'redirect_url' => 'cloudapp://billing/start',
    ]);
    $first = SaasTestContext::open($bridge->handleEncryptedRequest($buyEnvelope));

    $existingOrder = $harness->em->getRepository(Order::class)->find((int) $first['order_id']);
    $existingOrder?->setUnpaidInvoiceId(null);
    $harness->em->flush();

    $capturedInput = [];
    $updateEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'update',
        'email' => 'bob@example.com',
        'external_user_id' => '2002',
        'plan_id' => '200gb_monthly',
        'redirect_url' => 'https://app.cloudapp.test/billing/upgraded',
    ]);

    // Act
    $second = SaasTestContext::open($bridge->handleEncryptedRequest(
        $updateEnvelope,
        static function (array $input) use (&$capturedInput): array {
            $capturedInput = $input;

            return ['order_id' => (int) $input['existing_order_id'], 'invoice_hash' => 'upgrade-hash-1'];
        },
    ));

    // Assert
    $orders = $harness->em->getRepository(Order::class)->findAll();
    $updatedRecord = $harness->saasRepo->findByOrderId((int) $first['order_id']);

    expect($orders)->toHaveCount(1)
        ->and($capturedInput['existing_order_id'])->toBe((int) $first['order_id'])
        ->and($second['order_id'])->toBe($first['order_id'])
        ->and($second['invoice_hash'])->toBe('upgrade-hash-1')
        ->and($updatedRecord?->planId)->toBe('200gb_monthly')
        ->and($updatedRecord?->returnRedirectUrl)->toBe('https://app.cloudapp.test/billing/upgraded')
        ->and($updatedRecord?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PENDING_CLAIM);
});

it('rejects untrusted redirect_url without creating client or order records', function (string $badUrl): void {
    // Arrange
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test');
    $harness->seedSaasProduct('200gb_monthly', 150000.0);
    $bridge = (new CheckoutBridge(
        new EncryptedChannel(),
        SaasTestContext::createRegistry('https://api.cloudapp.test'),
        $harness->saasRepo,
    ))->setDi($harness->di);

    $envelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'buy',
        'email' => 'charlie@example.com',
        'plan_id' => '200gb_monthly',
        'redirect_url' => $badUrl,
    ]);

    // Act & Assert
    expect(fn (): array => $bridge->handleEncryptedRequest($envelope))
        ->toThrow(\InvalidArgumentException::class, 'Redirect URL is not allowed');
    expect($harness->em->getRepository(Order::class)->findAll())->toBeEmpty();
})->with([
    'untrusted external domain' => ['https://evil.example.com/phish'],
    'javascript XSS scheme' => ['javascript:alert(1)'],
    'data XSS scheme' => ['data:text/html,<script>alert(1)</script>'],
]);

it('defers plan mutation on provisioned action=update until upgrade invoice is paid and bills new plan price', function (): void {
    $recorder = new FakeTransportRecorder(
        SaasTestContext::TEST_SECRET,
        static fn (array $payload): array => ['status' => 'provisioned', 'external_user_id' => '3003'],
    );
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test', null, $recorder->asClosure());
    $harness->seedSaasProduct('20gb_monthly_v4', 9900.0, '1M');
    $harness->seedSaasProduct('100gb_monthly_v4', 29900.0, '1M');
    $registry = SaasTestContext::createRegistry('https://api.cloudapp.test');
    $bridge = (new CheckoutBridge(new EncryptedChannel(), $registry, $harness->saasRepo))->setDi($harness->di);

    // 1. Initial buy
    $buyEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'buy',
        'email' => 'upgrade@example.com',
        'external_user_id' => '3003',
        'plan_id' => '20gb_monthly_v4',
        'redirect_url' => 'https://app.cloudapp.test/gallery',
    ]);
    $buyResp = SaasTestContext::open($bridge->handleEncryptedRequest($buyEnvelope));
    $order = $harness->em->getRepository(Order::class)->find((int) $buyResp['order_id']);
    $invoice = $harness->em->getRepository(\Box\Mod\Invoice\Entity\Invoice::class)->find((int) $order?->getUnpaidInvoiceId());
    $harness->di['mod_service']('invoice')->payInvoiceWithCredits($invoice);

    // 2. Request upgrade to 100gb_monthly_v4 (do not pay yet)
    $updateEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'update',
        'email' => 'upgrade@example.com',
        'external_user_id' => '3003',
        'plan_id' => '100gb_monthly_v4',
        'plan_metadata' => ['storage_bytes' => 107_374_182_400, 'period' => '1M'],
        'redirect_url' => 'https://app.cloudapp.test/gallery',
    ]);
    $updateResp = SaasTestContext::open($bridge->handleEncryptedRequest($updateEnvelope));

    $harness->em->clear();
    $orderAfterUpdateReq = $harness->em->getRepository(Order::class)->find((int) $buyResp['order_id']);
    $upgradeInvoice = $harness->em->getRepository(\Box\Mod\Invoice\Entity\Invoice::class)->findOneBy(['hash' => $updateResp['invoice_hash']]);
    $recordBeforePay = $harness->saasRepo->findByOrderId((int) $buyResp['order_id']);

    // Plan stays on 20gb until upgrade invoice is paid, while invoice bills 29900
    expect($recordBeforePay?->planId)->toBe('20gb_monthly_v4')
        ->and((float) $upgradeInvoice?->getBaseIncome())->toBe(29900.0)
        ->and($orderAfterUpdateReq?->getConfig()['pending_upgrade']['plan_id'] ?? null)->toBe('100gb_monthly_v4');

    // 3. Renew/pay promotes pending upgrade cleanly
    $harness->saasService->renew($orderAfterUpdateReq);
    $recordAfterPay = $harness->saasRepo->findByOrderId((int) $buyResp['order_id']);
    expect($recordAfterPay?->planId)->toBe('100gb_monthly_v4')
        ->and($orderAfterUpdateReq?->getConfig()['pending_upgrade'] ?? null)->toBeNull();
});

it('matches single multi-period tier product for both monthly and yearly checkout requests', function (): void {
    $harness = new DoctrineBillingHarness('https://api.cloudapp.test');
    $product = $harness->seedSaasProduct('20gb_{period}_v4', 9900.0, '1M');
    $product->setConfig([
        'app_id' => 'cloudapp',
        'plan_id' => '20gb_{period}_v4',
        'plan_ids' => ['1M' => '20gb_monthly_v4', '1Y' => '20gb_yearly_v4'],
        'plan_metadata' => ['storage_bytes' => 21_474_836_480],
        'price' => 99000.0,
    ]);
    $harness->em->persist($product);
    $harness->em->flush();

    $registry = SaasTestContext::createRegistry('https://api.cloudapp.test');
    $bridge = (new CheckoutBridge(new EncryptedChannel(), $registry, $harness->saasRepo))->setDi($harness->di);

    $yearlyEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'action' => 'buy',
        'email' => 'yearly4prod@example.com',
        'external_user_id' => '4004',
        'plan_id' => '20gb_yearly_v4',
        'redirect_url' => 'https://app.cloudapp.test/gallery',
    ]);
    $resp = SaasTestContext::open($bridge->handleEncryptedRequest($yearlyEnvelope));

    $order = $harness->em->getRepository(Order::class)->find((int) $resp['order_id']);
    $record = $harness->saasRepo->findByOrderId((int) $resp['order_id']);

    expect($order?->getPeriod())->toBe('1Y')
        ->and($record?->planId)->toBe('20gb_yearly_v4');
});
