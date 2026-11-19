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

it('retains manual payment proof when SaaS provisioning fails and purges proof once retry succeeds (Option A)', function (): void {
    $saasDown = true;
    $recorder = new FakeTransportRecorder(
        SaasTestContext::TEST_SECRET,
        function (array $payload) use (&$saasDown): array {
            if ($saasDown) {
                throw new \RuntimeException('Remote SaaS endpoint unavailable (HTTP 503)');
            }

            return ['status' => 'provisioned', 'external_user_id' => 'ext-777', 'order_id' => $payload['order_id'] ?? 0];
        },
    );

    $harness = new DoctrineBillingHarness('https://api.cloudapp.test', null, $recorder->asClosure());
    $harness->connection->executeStatement('CREATE TABLE IF NOT EXISTS invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, rel_id INTEGER, type TEXT)');
    $harness->seedSaasProduct('200gb_monthly', 150000.0);

    $proofDir = sys_get_temp_dir() . '/saas_opt_a_' . bin2hex(random_bytes(5));
    $srcDir = sys_get_temp_dir() . '/saas_opt_a_src_' . bin2hex(random_bytes(5));
    mkdir($proofDir, 0750, true);
    mkdir($srcDir, 0750, true);

    try {
        $storage = new \Box\Mod\Servicesaas\Bridge\ManualProofStorage($proofDir);
        $harness->saasService->setProofStorage($storage);

        $bridge = (new CheckoutBridge(
            new EncryptedChannel(),
            SaasTestContext::createRegistry('https://api.cloudapp.test'),
            $harness->saasRepo,
        ))->setDi($harness->di);

        $resp = SaasTestContext::open($bridge->handleEncryptedRequest(SaasTestContext::seal('cloudapp', [
            'app_id' => 'cloudapp',
            'action' => 'buy',
            'email' => 'optiona@example.com',
            'external_user_id' => '777',
            'plan_id' => '200gb_monthly',
            'redirect_url' => 'https://app.cloudapp.test/done',
        ])));

        $order = $harness->em->getRepository(Order::class)->find((int) $resp['order_id']);
        $invoice = $harness->em->getRepository(\Box\Mod\Invoice\Entity\Invoice::class)->findOneBy(['hash' => $resp['invoice_hash']]);
        $invoiceId = (int) $invoice?->getId();
        $harness->connection->insert('invoice_item', ['invoice_id' => $invoiceId, 'rel_id' => $order?->getId(), 'type' => 'order']);

        $pngPath = $srcDir . '/proof.png';
        file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+X2kAAAAASUVORK5CYII='));
        $stored = $storage->storeUploadedProof($invoiceId, new \Symfony\Component\HttpFoundation\File\UploadedFile($pngPath, 'proof.png', 'image/png', null, true));

        // 1. Remote SaaS is down -> activation throws & payment proof MUST stay on disk for retry
        expect(fn () => $harness->saasService->activate($order))
            ->toThrow(\RuntimeException::class, 'Remote SaaS endpoint unavailable');

        expect($storage->readProof($invoiceId))->not->toBeNull()
            ->and(is_file($proofDir . '/' . $stored['filename']))->toBeTrue()
            ->and($harness->saasService->getStatusForOrder((int) $order?->getId())['manual_proofs'] ?? [])->toHaveCount(1);

        // 2. Remote SaaS recovers -> Admin retries Activate/Resync -> proof is auto-purged
        $saasDown = false;
        expect($harness->saasService->activate($order))->toBeTrue()
            ->and($storage->readProof($invoiceId))->toBeNull()
            ->and(is_file($proofDir . '/' . $stored['filename']))->toBeFalse()
            ->and($harness->saasService->getStatusForOrder((int) $order?->getId())['manual_proofs'] ?? [])->toBe([]);
    } finally {
        foreach ([$proofDir, $srcDir] as $dir) {
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $item) {
                    if ($item !== '.' && $item !== '..') {
                        @unlink($dir . '/' . $item);
                    }
                }
                @rmdir($dir);
            }
        }
    }
});
