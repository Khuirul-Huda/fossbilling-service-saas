<?php

declare(strict_types=1);

use Box\Mod\Servicesaas\Api\Admin;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;

it('auto-generates secrets on new apps, preserves secret on masked edit, rotates secrets, and exports pairing config', function (): void {
    $harness = new DoctrineBillingHarness('http://remote-app.test');
    $store = new class {
        /** @var array<string, mixed> */
        public array $config = ['apps' => []];

        public function getConfig(string $ext): array
        {
            return $this->config;
        }

        public function setConfig(array $data): bool
        {
            $this->config['apps'] = $data['apps'] ?? [];

            return true;
        }
    };

    $di = $harness->di;
    $di['mod_service'] = $di->protect(static function (string $name) use ($harness, $store): object {
        if (strtolower($name) === 'extension') {
            return $store;
        }

        return match (strtolower($name)) {
            'client' => $harness->di->raw('mod_service')('client'),
            'order' => $harness->di->raw('mod_service')('order'),
            'invoice' => $harness->di->raw('mod_service')('invoice'),
            'servicesaas' => $harness->saasService,
        };
    });
    // Re-bind mod_config to inspect live extension store updates cleanly.
    $di['mod_config'] = $di->protect(static fn (string $mod): array => $mod === 'servicesaas' ? $store->config : []);

    $admin = new class ($di, $harness->saasService) extends Admin {
        public function __construct(private readonly \Pimple\Container $testDi, private readonly \Box\Mod\Servicesaas\Service $svc)
        {
        }

        public function getDi(): ?\Pimple\Container
        {
            return $this->testDi;
        }

        public function getService(): \Box\Mod\Servicesaas\Service
        {
            return $this->svc;
        }

        public function checkPermissions(string $module, ?string $key = null, mixed $constraint = null): void
        {
        }
    };

    // Reset service cached registry so mod_config fake is read live.
    $harness->saasService->resetRegistry();
    $saved = $admin->save_app([
        'app_id' => 'sebarke',
        'name' => 'Sebarke Cloud',
        'base_url' => 'https://stream.sebarke.id',
        'allowed_redirect_hosts' => 'stream.sebarke.id',
    ]);
    expect($saved)->toBeTrue();

    $initialSecret = $store->config['apps']['sebarke']['shared_secret'] ?? '';
    expect($initialSecret)->toHaveLength(64)
        ->and(ctype_xdigit($initialSecret))->toBeTrue();

    // Editing with masked/empty secret preserves initial auto-generated secret.
    $admin->save_app([
        'app_id' => 'sebarke',
        'name' => 'Sebarke Cloud Pro',
        'base_url' => 'https://stream.sebarke.id',
        'shared_secret' => '********',
    ]);
    expect($store->config['apps']['sebarke']['shared_secret'])->toBe($initialSecret);

    // Export pairing config contains JSON + env lines.
    $pairing = $admin->pairing_config(['app_id' => 'sebarke']);
    $decodedJson = json_decode((string) $pairing['pairing_json'], true);
    expect($decodedJson['app_id'])->toBe('sebarke')
        ->and($decodedJson['shared_secret'])->toBe($initialSecret)
        ->and($pairing['env_snippet'])->toContain('FOSSBILLING_SHARED_SECRET=' . $initialSecret);

    // Rotate secret updates stored secret cleanly.
    $rotated = $admin->generate_secret(['app_id' => 'sebarke', 'persist' => true]);
    expect($rotated['rotated'])->toBeTrue()
        ->and($rotated['shared_secret'])->toHaveLength(64)
        ->and($rotated['shared_secret'])->not->toBe($initialSecret)
        ->and($store->config['apps']['sebarke']['shared_secret'])->toBe($rotated['shared_secret']);
});
