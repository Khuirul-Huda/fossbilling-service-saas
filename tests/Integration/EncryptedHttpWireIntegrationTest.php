<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Bridge\AccountEventSync;
use Box\Mod\Servicesaas\Bridge\CheckoutBridge;
use Box\Mod\Servicesaas\Bridge\EntitlementClaimer;
use Box\Mod\Servicesaas\Client\ProvisioningClient;
use Box\Mod\Servicesaas\Client\ProvisioningPayload;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Service;
use Box\Mod\Servicesaas\Tests\Support\RemoteAppServerProcess;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

it('completes real encrypted HTTP webhook exchange between ProvisioningClient and remote app server', function (): void {
    // Arrange
    $remote = new RemoteAppServerProcess();
    $remoteUrl = $remote->start();

    try {
        $remote->postJson('/_test/users', ['user_id' => 501, 'email' => 'Alice@Example.com']);

        $client = new ProvisioningClient(new EncryptedChannel(), SaasTestContext::createRegistry($remoteUrl));
        $expiresMicro = (time() + 86400) * 1_000_000;

        // Act 1: Activate
        $actResp = $client->dispatch(new ProvisioningPayload([
            'action' => 'activate',
            'app_id' => 'cloudapp',
            'order_id' => 42,
            'client_id' => 7,
            'email' => 'alice@example.com',
            'plan_id' => 'pro_200gb',
            'plan_metadata' => ['storage_bytes' => 214_748_364_800],
            'expires_at_micro' => $expiresMicro,
        ]));

        // Assert 1
        $sub = $remote->getSubscription(501);
        expect($actResp['status'])->toBe('provisioned')
            ->and((int) $actResp['external_user_id'])->toBe(501)
            ->and($sub['plan_id'])->toBe('pro_200gb')
            ->and((int) $sub['storage_bytes'])->toBe(214_748_364_800)
            ->and($sub['ext_tx_id'])->toBe('fb:42');

        // Act 2: Suspend -> Free downgrade
        $suspResp = $client->dispatch(new ProvisioningPayload([
            'action' => 'suspend',
            'app_id' => 'cloudapp',
            'order_id' => 42,
            'client_id' => 7,
            'email' => 'alice@example.com',
            'external_user_id' => '501',
            'plan_id' => 'pro_200gb',
        ]));

        // Assert 2
        $downgraded = $remote->getSubscription(501);
        expect($suspResp['status'])->toBe('downgraded')
            ->and($downgraded['plan_id'])->toBe('free')
            ->and((int) $downgraded['storage_bytes'])->toBe(0);
    } finally {
        $remote->stop();
    }
});

it('integrates CheckoutBridge, ProvisioningClient, EntitlementClaimer, and AccountEventSync with SQLite DBAL', function (): void {
    // Arrange
    $remote = new RemoteAppServerProcess();
    $remoteUrl = $remote->start();

    try {
        $repo = SaasTestContext::createRepository();
        $registry = SaasTestContext::createRegistry($remoteUrl);
        $channel = new EncryptedChannel();
        $service = new Service($repo, new ProvisioningClient($channel, $registry), $registry);

        $bridge = new CheckoutBridge($channel, $registry, $repo);
        $claimer = new EntitlementClaimer($channel, $registry, $repo);
        $eventSync = new AccountEventSync($channel, $registry, $repo);

        // Act 1: Encrypted storefront pre-purchase checkout
        $checkoutEnv = SaasTestContext::seal('cloudapp', [
            'app_id' => 'cloudapp',
            'action' => 'buy',
            'email' => 'prebuyer@example.com',
            'plan_id' => 'pro_200gb',
            'redirect_url' => 'https://app.cloudapp.test/billing/done',
            'plan_metadata' => ['storage_bytes' => 214_748_364_800],
        ]);
        $checkoutRes = SaasTestContext::open($bridge->handleEncryptedRequest(
            $checkoutEnv,
            static fn (array $input): array => ['order_id' => 77, 'invoice_hash' => 'inv-77', 'client_id' => 9],
            static fn (): string => 'https://billing.example.test',
        ));
        expect($checkoutRes['checkout_url'])->toBe('https://billing.example.test/invoice/inv-77');

        // Act 2: Activate while unregistered on remote app -> pending_claim
        $service->activate(['id' => 77, 'client_id' => 9, 'period' => '1M', 'config' => ['app_id' => 'cloudapp', 'plan_id' => 'pro_200gb', 'target_email' => 'prebuyer@example.com']]);
        expect($repo->findByOrderId(77)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PENDING_CLAIM);

        // Act 3: Remote user registers later and claims entitlement
        $claimRes = SaasTestContext::open($claimer->handleEncryptedClaim(
            SaasTestContext::seal('cloudapp', ['app_id' => 'cloudapp', 'email' => 'PreBuyer@Example.com', 'external_user_id' => '901']),
        ));
        expect($claimRes['matched'])->toBeTrue()
            ->and($repo->findByOrderId(77)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED);

        // Act 4: Email change sync
        $eventRes = SaasTestContext::open($eventSync->handleEncryptedEvent(
            SaasTestContext::seal('cloudapp', ['app_id' => 'cloudapp', 'event' => 'email_changed', 'external_user_id' => '901', 'email' => 'updated@example.com']),
        ));
        expect($eventRes['status'])->toBe('ok')
            ->and($repo->findByOrderId(77)?->targetEmail)->toBe('updated@example.com');
    } finally {
        $remote->stop();
    }
});
