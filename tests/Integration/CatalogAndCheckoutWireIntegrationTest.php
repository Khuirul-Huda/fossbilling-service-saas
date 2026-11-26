<?php

declare(strict_types=1);

use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;
use Box\Mod\Servicesaas\Tests\Support\PhpServerProcess;
use Box\Mod\Servicesaas\Tests\Support\RemoteAppServerProcess;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

function postCatalogWireJson(string $url, array $payload): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    expect($raw)->not->toBeFalse();
    $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    return $decoded['result'] ?? $decoded;
}

it('completes live encrypted HTTP catalog sync, 1Y checkout, addon_activate, and dynamic_amount invoice_paid webhooks', function (): void {
    $remoteApp = new RemoteAppServerProcess();
    $remoteBaseUrl = $remoteApp->start();
    $dbPath = sys_get_temp_dir() . '/servicesaas_catalog_wire_' . bin2hex(random_bytes(4)) . '.sqlite';
    $server = new PhpServerProcess();
    $fbBaseUrl = $server->start($dbPath, $remoteBaseUrl);
    $channel = EncryptedChannel::persistent();

    try {
        $remoteApp->postJson('/_test/users', ['user_id' => 701, 'email' => 'wire-catalog@cloudapp.test']);

        // 1. Remote app syncs annual plan, one-time addon, and dynamic top-up via live HTTP.
        $syncEnvelope = $channel->seal('cloudapp', [
            'app_id' => 'cloudapp',
            'plans' => [
                [
                    'plan_id' => 'pro_seller',
                    'title' => 'Pro Seller',
                    'kind' => 'plan',
                    'pricing_mode' => 'fixed',
                    'billing_type' => 'recurrent',
                    'prices' => ['1M' => 129000, '1Y' => 1290000],
                    'enabled_periods' => ['1M', '1Y'],
                    'active' => true,
                ],
                [
                    'plan_id' => 'extra_destination',
                    'title' => '+1 Destinasi',
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
        ], SaasTestContext::TEST_SECRET)->toArray();

        $syncReply = $channel->open(
            EnvelopeDto::fromArray(postCatalogWireJson($fbBaseUrl . '/api/guest/servicesaas/sync_catalog', $syncEnvelope)),
            SaasTestContext::TEST_SECRET,
        );
        expect($syncReply['created'])->toBe(3);

        // 2. Checkout 1Y plan, add-on, and dynamic top-up over live HTTP.
        $planSession = $channel->open(
            EnvelopeDto::fromArray(postCatalogWireJson($fbBaseUrl . '/api/guest/servicesaas/checkout_session', $channel->seal('cloudapp', [
                'app_id' => 'cloudapp',
                'external_user_id' => '701',
                'email' => 'wire-catalog@cloudapp.test',
                'plan_id' => 'pro_seller',
                'action' => 'buy',
                'plan_metadata' => ['item_type' => 'plan', 'period' => '1Y'],
            ], SaasTestContext::TEST_SECRET)->toArray())),
            SaasTestContext::TEST_SECRET,
        );

        $addonSession = $channel->open(
            EnvelopeDto::fromArray(postCatalogWireJson($fbBaseUrl . '/api/guest/servicesaas/checkout_session', $channel->seal('cloudapp', [
                'app_id' => 'cloudapp',
                'external_user_id' => '701',
                'email' => 'wire-catalog@cloudapp.test',
                'plan_id' => 'extra_destination',
                'action' => 'buy',
                'plan_metadata' => ['item_type' => 'addon', 'period' => 'once'],
            ], SaasTestContext::TEST_SECRET)->toArray())),
            SaasTestContext::TEST_SECRET,
        );

        $topupSession = $channel->open(
            EnvelopeDto::fromArray(postCatalogWireJson($fbBaseUrl . '/api/guest/servicesaas/checkout_session', $channel->seal('cloudapp', [
                'app_id' => 'cloudapp',
                'external_user_id' => '701',
                'email' => 'wire-catalog@cloudapp.test',
                'plan_id' => 'topup_saldo',
                'action' => 'buy',
                'plan_metadata' => ['item_type' => 'topup', 'amount_idr' => 90000, 'period' => 'once'],
            ], SaasTestContext::TEST_SECRET)->toArray())),
            SaasTestContext::TEST_SECRET,
        );

        // 3. Activate orders on shared SQLite DB -> verify FOSSBilling emits activate, addon_activate, & invoice_paid webhooks.
        $harness = new DoctrineBillingHarness($remoteBaseUrl, $dbPath);
        foreach ([$planSession['order_id'], $addonSession['order_id'], $topupSession['order_id']] as $orderId) {
            $order = $harness->em->getRepository(Order::class)->find($orderId);
            expect($order)->not->toBeNull();
            $harness->saasService->activate($order);
        }

        $events = $remoteApp->readEvents();
        $actions = array_map(static fn (array $e): string => (string) ($e['action'] ?? ''), $events);

        expect($actions)->toContain('activate')
            ->toContain('addon_activate')
            ->toContain('invoice_paid');
    } finally {
        $server->stop();
        $remoteApp->stop();
        @unlink($dbPath);
    }
});
