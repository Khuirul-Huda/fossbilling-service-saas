<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;
use Box\Mod\Servicesaas\Tests\Support\PhpServerProcess;
use Box\Mod\Servicesaas\Tests\Support\RemoteAppServerProcess;

beforeEach(function (): void {
    if (!class_exists(Order::class)) {
        $this->markTestSkipped('FOSSBilling core ORM entities are not installed in standalone CI.');
    }
});

/** @return array{tmpDir: string, dbPath: string, remote: RemoteAppServerProcess, php: PhpServerProcess, remoteUrl: string, phpUrl: string} */
function bootFullSaasE2eCluster(): array
{
    $tmpDir = sys_get_temp_dir() . '/servicesaas_e2e_' . bin2hex(random_bytes(5));
    mkdir($tmpDir, 0775, true);
    $dbPath = $tmpDir . '/e2e.sqlite';

    $php = new PhpServerProcess();
    $phpUrl = $php->start($dbPath, 'http://127.0.0.1:0');

    $remote = new RemoteAppServerProcess();
    $remoteUrl = $remote->start($phpUrl, 'cloudapp');

    return compact('tmpDir', 'dbPath', 'remote', 'php', 'remoteUrl', 'phpUrl');
}

function shutdownFullSaasE2eCluster(array $cluster): void
{
    $cluster['php']->stop();
    $cluster['remote']->stop();
    @unlink($cluster['dbPath']);
    @rmdir($cluster['tmpDir']);
}

function markInvoicePaidAndActivate(DoctrineBillingHarness $harness, Invoice $invoice): void
{
    $tx = $harness->createTransactionForInvoice($invoice);
    $tx->setStatus(\Box\Mod\Invoice\Entity\Transaction::STATUS_PROCESSED);
    $harness->em->flush();

    $invoice->setStatus(Invoice::STATUS_PAID);
    $harness->em->flush();

    foreach ($harness->em->getRepository(Order::class)->findAll() as $order) {
        if ($order instanceof Order && $order->getUnpaidInvoiceId() === $invoice->getId()) {
            $order->setUnpaidInvoiceId(null);
            $order->setStatus(Order::STATUS_ACTIVE);
            $harness->em->flush();
            $harness->saasService->activate($order);
        }
    }
}

function assertHttp302Redirect(string $url, string $expectedLocation): void
{
    $handle = curl_init($url);
    expect($handle)->not->toBeFalse();
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    $raw = curl_exec($handle);
    $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

    expect($httpCode)->toBe(302)->and((string) $raw)->toContain('Location: ' . $expectedLocation);
}

it('completes in-app checkout, payment activation, return redirect, and suspend/unsuspend downgrade/restore E2E', function (): void {
    $cluster = bootFullSaasE2eCluster();

    try {
        $cluster['remote']->postJson('/_test/users', ['user_id' => 101, 'email' => 'alice@example.com']);
        $harness = new DoctrineBillingHarness($cluster['remoteUrl'], $cluster['dbPath']);
        $harness->seedSaasProduct('pro_200gb', 150000.0, '1M');

        $checkout = $cluster['remote']->postJson('/_test/trigger-checkout', [
            'user_id' => 101,
            'plan_id' => 'pro_200gb',
            'action' => 'buy',
            'redirect_url' => 'https://app.cloudapp.test/billing/done',
        ]);
        $orderId = (int) ($checkout['order_id'] ?? 0);
        expect($orderId)->toBeGreaterThan(0);

        $harness->em->clear();
        $invoice = $harness->em->getRepository(Invoice::class)->findOneBy(['hash' => (string) $checkout['invoice_hash']]);
        expect($invoice)->toBeInstanceOf(Invoice::class);

        markInvoicePaidAndActivate($harness, $invoice);

        $activeSub = $cluster['remote']->getSubscription(101);
        $record = $harness->saasRepo->findByOrderId($orderId);
        expect($activeSub['plan_id'])->toBe('pro_200gb')
            ->and((int) $activeSub['storage_bytes'])->toBe(214_748_364_800)
            ->and($activeSub['ext_tx_id'])->toBe('fb:' . $orderId)
            ->and($record?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED);

        assertHttp302Redirect(
            $cluster['phpUrl'] . '/servicesaas/return/' . $orderId,
            'https://app.cloudapp.test/billing/done?status=success',
        );

        $order = $harness->em->getRepository(Order::class)->find($orderId);
        $harness->saasService->suspend($order);
        $downgradedSub = $cluster['remote']->getSubscription(101);
        expect($downgradedSub['plan_id'])->toBe('free')
            ->and((int) $downgradedSub['storage_bytes'])->toBe(0)
            ->and($harness->saasRepo->findByOrderId($orderId)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_SUSPENDED);

        $harness->saasService->unsuspend($order);
        $restoredSub = $cluster['remote']->getSubscription(101);
        expect($restoredSub['plan_id'])->toBe('pro_200gb')
            ->and((int) $restoredSub['storage_bytes'])->toBe(214_748_364_800)
            ->and($harness->saasRepo->findByOrderId($orderId)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED);
    } finally {
        shutdownFullSaasE2eCluster($cluster);
    }
});

it('completes storefront pre-purchase pending_claim and post-signup entitlement claim E2E', function (): void {
    $cluster = bootFullSaasE2eCluster();

    try {
        $harness = new DoctrineBillingHarness($cluster['remoteUrl'], $cluster['dbPath']);
        $client = $harness->seedClient('prebuyer@example.com');
        $product = $harness->seedSaasProduct('pro_200gb', 150000.0, '1M');

        $orderId = (int) $harness->di['mod_service']('order')->createOrder($client, $product, [
            'invoice_option' => 'issue-invoice',
            'config' => ['app_id' => 'cloudapp', 'plan_id' => 'pro_200gb', 'target_email' => 'prebuyer@example.com'],
        ]);
        $order = $harness->em->getRepository(Order::class)->find($orderId);
        $invoice = $harness->em->getRepository(Invoice::class)->find((int) $order?->getUnpaidInvoiceId());
        expect($order)->toBeInstanceOf(Order::class)
            ->and($invoice)->toBeInstanceOf(Invoice::class);

        markInvoicePaidAndActivate($harness, $invoice);

        $preClaim = $harness->saasRepo->findByOrderId($orderId);
        expect($preClaim?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PENDING_CLAIM);

        $order->setExpiresAt((new \DateTime())->setTimestamp(time() - 86400));
        $harness->em->flush();
        $harness->em->clear();

        $signup = $cluster['remote']->postJson('/_test/signup', ['email' => 'PreBuyer@Example.com']);
        $newUserId = (int) ($signup['user_id'] ?? 0);
        expect($signup['matched'])->toBeTrue()->and($newUserId)->toBeGreaterThan(0);

        $claimedSub = $cluster['remote']->getSubscription($newUserId);
        $claimedRecord = $harness->saasRepo->findByOrderId($orderId);
        $refreshedOrder = $harness->em->getRepository(Order::class)->find($orderId);

        expect($claimedSub['plan_id'])->toBe('pro_200gb')
            ->and((int) $claimedSub['storage_bytes'])->toBe(214_748_364_800)
            ->and($claimedRecord?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED)
            ->and($claimedRecord?->externalUserId)->toBe((string) $newUserId)
            ->and($refreshedOrder?->getExpiresAt()?->getTimestamp())->toBeGreaterThan(strtotime('+25 days'));
    } finally {
        shutdownFullSaasE2eCluster($cluster);
    }
});
