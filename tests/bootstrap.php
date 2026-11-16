<?php

declare(strict_types=1);

if (defined('SERVICESAAS_TEST_BOOTSTRAPPED')) {
    return;
}
define('SERVICESAAS_TEST_BOOTSTRAPPED', true);

if (!defined('DEBUG')) {
    define('DEBUG', false);
}

$sampleConfig = dirname(__DIR__, 3) . '/config-sample.php';
if (!defined('PATH_CONFIG') && is_file($sampleConfig)) {
    define('PATH_CONFIG', $sampleConfig);
}

if (!function_exists('__trans')) {
    /** @param array<string, mixed>|null $values */
    function __trans(string $msgid, ?array $values = null, ?string $from = null): string
    {
        return $values ? strtr($msgid, $values) : $msgid;
    }
}

if (!function_exists('__pluralTrans')) {
    /** @param array<string, mixed>|null $values */
    function __pluralTrans(string $msgid, string $msgidPlural, int $number, ?array $values = null, ?string $from = null): string
    {
        return $values ? strtr($msgid, $values) : $msgid;
    }
}

$moduleRoot = dirname(__DIR__);
$autoloadCandidates = [
    $moduleRoot . '/vendor/autoload.php',
    dirname($moduleRoot, 2) . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadFile) {
    if (is_file($autoloadFile)) {
        require_once $autoloadFile;
        break;
    }
}

$namespaceMap = [
    'Box\\Mod\\Servicesaas\\Crypto\\' => $moduleRoot . '/src/Crypto/',
    'Box\\Mod\\Servicesaas\\Persistence\\' => $moduleRoot . '/src/Persistence/',
    'Box\\Mod\\Servicesaas\\Client\\' => $moduleRoot . '/src/Client/',
    'Box\\Mod\\Servicesaas\\Bridge\\' => $moduleRoot . '/src/Bridge/',
    'Box\\Mod\\Servicesaas\\Tests\\' => __DIR__ . '/',
    'Box\\Mod\\Servicesaas\\' => $moduleRoot . '/',
];

spl_autoload_register(static function (string $class) use ($namespaceMap): void {
    foreach ($namespaceMap as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
}, true, true);

if (!interface_exists(\FOSSBilling\Core\Container\InjectionAwareInterface::class)) {
    require_once __DIR__ . '/Support/FossbillingStandaloneStubs.php';
}
