<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Bridge\AccountEventSync;
use Box\Mod\Servicesaas\Bridge\PortalSso;
use Box\Mod\Servicesaas\Client\ProvisioningClient;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRecord;
use Box\Mod\Servicesaas\Service;
use Box\Mod\Servicesaas\Tests\Support\FakeTransportRecorder;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

it('executes full Service lifecycle and action_* aliases against SQLite persistence', function (): void {
    // Arrange
    $recorder = new FakeTransportRecorder(
        SaasTestContext::TEST_SECRET,
        static fn (array $payload): array => [
            'status' => in_array($payload['action'], ['suspend', 'cancel'], true) ? 'downgraded' : 'provisioned',
            'external_user_id' => '501',
        ],
    );
    $repo = SaasTestContext::createRepository();
    $registry = SaasTestContext::createRegistry('https://api.cloudapp.test');
    $service = new Service($repo, new ProvisioningClient(new EncryptedChannel(), $registry, $recorder->asClosure()), $registry);

    $order = [
        'id' => 701,
        'client_id' => 11,
        'period' => '1M',
        'config' => [
            'app_id' => 'cloudapp',
            'plan_id' => '200gb_monthly',
            'target_email' => 'life@example.com',
            'plan_metadata' => ['storage_bytes' => 214_748_364_800],
        ],
    ];

    // Act & Assert: create -> activate -> renew -> suspend -> unsuspend -> cancel -> uncancel -> delete
    $created = $service->create($order);
    expect($created->getId())->toBeInt()->toBeGreaterThan(0);
    expect($service->action_create($order)->getId())->toBe($created->getId());

    expect($service->activate($order))->toBeTrue();
    expect($repo->findByOrderId(701)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED);
    expect($repo->findByOrderId(701)?->externalUserId)->toBe('501');

    expect($service->action_renew($order))->toBeTrue();
    expect($service->suspend($order))->toBeTrue();
    expect($repo->findByOrderId(701)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_SUSPENDED);

    expect($service->action_unsuspend($order))->toBeTrue();
    expect($repo->findByOrderId(701)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED);

    expect($service->action_cancel($order))->toBeTrue();
    expect($repo->findByOrderId(701)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_CANCELED);

    expect($service->action_uncancel($order))->toBeTrue();
    expect($repo->findByOrderId(701)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_PROVISIONED);

    $actionsSent = array_map(static fn (array $r): string => $r['payload']['action'], $recorder->requests);
    expect($actionsSent)->toBe(['activate', 'renew', 'suspend', 'unsuspend', 'cancel', 'uncancel']);

    $service->action_delete($order);
    expect($repo->findByOrderId(701))->toBeNull();
});

it('handles email_changed and account_deleted encrypted events via AccountEventSync', function (): void {
    // Arrange
    $repo = SaasTestContext::createRepository();
    $repo->insert(new ServiceSaasRecord([
        'client_id' => 25,
        'order_id' => 880,
        'app_id' => 'cloudapp',
        'plan_id' => '200gb_monthly',
        'target_email' => 'old@example.com',
        'external_user_id' => '8080',
        'provisioning_status' => ServiceSaasRecord::STATUS_PROVISIONED,
    ]));

    $sync = new AccountEventSync(
        new EncryptedChannel(),
        SaasTestContext::createRegistry('https://api.cloudapp.test'),
        $repo,
    );

    $capturedNewEmail = null;
    $emailChangedEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'event' => AccountEventSync::EVENT_EMAIL_CHANGED,
        'external_user_id' => '8080',
        'email' => 'NewEmail@Example.com',
    ]);

    // Act 1: email_changed
    $emailResp = SaasTestContext::open($sync->handleEncryptedEvent(
        $emailChangedEnvelope,
        static function (?ServiceSaasRecord $rec, string $newEmail) use (&$capturedNewEmail): void {
            $capturedNewEmail = $newEmail;
        },
    ));

    // Assert 1
    expect($emailResp['status'])->toBe('ok')
        ->and($capturedNewEmail)->toBe('newemail@example.com')
        ->and($repo->findByOrderId(880)?->targetEmail)->toBe('newemail@example.com');

    // Act 2: account_deleted
    $deletedEnvelope = SaasTestContext::seal('cloudapp', [
        'app_id' => 'cloudapp',
        'event' => AccountEventSync::EVENT_ACCOUNT_DELETED,
        'external_user_id' => '8080',
    ]);
    $deleteResp = SaasTestContext::open($sync->handleEncryptedEvent($deletedEnvelope));

    // Assert 2
    expect($deleteResp['status'])->toBe('ok')
        ->and($repo->findByOrderId(880)?->provisioningStatus)->toBe(ServiceSaasRecord::STATUS_CANCELED);
});

it('resolves encrypted single-use PortalSso tokens and rejects replayed tokens', function (): void {
    $repo = SaasTestContext::createRepository();
    $repo->insert(new ServiceSaasRecord([
        'client_id' => 44,
        'order_id' => 910,
        'app_id' => 'cloudapp',
        'plan_id' => '100gb_monthly_v4',
        'target_email' => 'portal@example.com',
        'external_user_id' => '9090',
        'provisioning_status' => ServiceSaasRecord::STATUS_PROVISIONED,
    ]));

    $envelope = SaasTestContext::seal('cloudapp', [
        'external_user_id' => '9090',
        'email' => 'portal@example.com',
    ]);
    $token = rtrim(strtr(base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    $sso = new PortalSso(
        new EncryptedChannel(),
        SaasTestContext::createRegistry('https://api.cloudapp.test'),
        $repo,
    );

    $target = $sso->resolvePortalTarget($token);
    expect($target['client_id'])->toBe(44)
        ->and($target['redirect_path'])->toBe('/order/service/manage/910');

    expect(fn (): array => $sso->resolvePortalTarget($token))
        ->toThrow(\RuntimeException::class, 'Duplicate request_id');
});
