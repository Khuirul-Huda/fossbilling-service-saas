<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Client\AppConfig;
use Box\Mod\Servicesaas\Client\AppRegistry;

it('loads multiple apps from array or JSON config', function (): void {
    // Arrange
    $appsPayload = [
        'cloudapp' => [
            'name' => 'CloudApp Storage',
            'base_url' => 'https://api.cloudapp.example',
            'shared_secret' => str_repeat('a', 64),
            'allowed_redirect_hosts' => ['app.cloudapp.example', 'cloudapp.example'],
        ],
        'notesapp' => [
            'name' => 'NotesApp',
            'base_url' => 'https://api.notesapp.example',
            'webhook_path' => '/hooks/billing',
            'shared_secret' => str_repeat('b', 64),
            'allowed_redirect_hosts' => 'app.notesapp.example, portal.notesapp.example',
            'allowed_custom_schemes' => ['notesapp'],
        ],
    ];

    // Act
    $fromArray = new AppRegistry(['apps' => $appsPayload]);
    $fromJson = new AppRegistry(['apps' => json_encode($appsPayload, JSON_THROW_ON_ERROR)]);

    // Assert
    expect($fromArray->has('cloudapp'))->toBeTrue()
        ->and($fromArray->has('notesapp'))->toBeTrue()
        ->and($fromArray->get('cloudapp')->getWebhookUrl())->toBe('https://api.cloudapp.example/billing/notify/fossbilling')
        ->and($fromArray->get('notesapp')->getWebhookUrl())->toBe('https://api.notesapp.example/hooks/billing')
        ->and($fromJson->get('notesapp')->allowedRedirectHosts)->toBe(['app.notesapp.example', 'portal.notesapp.example'])
        ->and($fromJson->get('notesapp')->allowedCustomSchemes)->toBe(['notesapp']);
});

it('loads default app from flat fallback keys when apps map omits it and rejects unregistered apps', function (): void {
    // Arrange
    $registry = new AppRegistry([
        'default_base_url' => 'https://api.defaultapp.example/',
        'default_shared_secret' => str_repeat('c', 64),
        'default_app_name' => 'Default Cloud App',
        'default_allowed_hosts' => 'app.defaultapp.example',
    ]);

    // Act
    $app = $registry->get('default');

    // Assert
    expect($app->name)->toBe('Default Cloud App')
        ->and($app->getWebhookUrl())->toBe('https://api.defaultapp.example/billing/notify/fossbilling')
        ->and($app->allowedRedirectHosts)->toBe(['app.defaultapp.example']);

    expect(fn (): AppConfig => $registry->get('missing_app'))
        ->toThrow(\RuntimeException::class, 'not registered');
});

it('allows trusted HTTPS hosts, subdomains, and custom schemes while blocking untrusted hosts and XSS schemes', function (): void {
    // Arrange
    $config = AppConfig::fromArray([
        'app_id' => 'cloudapp',
        'name' => 'CloudApp',
        'base_url' => 'https://api.cloudapp.example',
        'shared_secret' => str_repeat('d', 64),
        'allowed_redirect_hosts' => ['app.cloudapp.example'],
        'allowed_custom_schemes' => ['cloudapp', 'saasdesktop'],
    ]);

    // Assert allowed URLs
    expect($config->isRedirectUrlAllowed('https://app.cloudapp.example/billing/done?plan=200gb'))->toBeTrue()
        ->and($config->isRedirectUrlAllowed('https://web.app.cloudapp.example/settings'))->toBeTrue()
        ->and($config->isRedirectUrlAllowed('cloudapp://billing/success'))->toBeTrue()
        ->and($config->isRedirectUrlAllowed('saasdesktop://billing/complete'))->toBeTrue();

    // Assert blocked URLs
    expect($config->isRedirectUrlAllowed('https://evil.example.com/steal'))->toBeFalse()
        ->and($config->isRedirectUrlAllowed('https://app.cloudapp.example.evil.com/phish'))->toBeFalse()
        ->and($config->isRedirectUrlAllowed('javascript:alert(document.cookie)'))->toBeFalse()
        ->and($config->isRedirectUrlAllowed('data:text/html,<script>alert(1)</script>'))->toBeFalse()
        ->and($config->isRedirectUrlAllowed('vbscript:msgbox(1)'))->toBeFalse()
        ->and($config->isRedirectUrlAllowed('cloudapp://'))->toBeFalse()
        ->and($config->isRedirectUrlAllowed("https://app.cloudapp.example/ok\r\nSet-Cookie: evil=1"))->toBeFalse();
});
