<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Client\Entity\Client;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Bridge\CheckoutBridge;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;
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
