<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Api\Guest;
use Box\Mod\Servicesaas\Tests\Support\DoctrineBillingHarness;

$dbPath = getenv('SERVICESAAS_E2E_DB') ?: '';
$remoteAppUrl = getenv('SERVICESAAS_E2E_REMOTE_URL') ?: 'http://127.0.0.1:0';
if ($dbPath === '') {
    http_response_code(500);
    echo json_encode(['error' => 'SERVICESAAS_E2E_DB is required']);

    return;
}

$harness = new DoctrineBillingHarness($remoteAppUrl, $dbPath);
$guestApi = new Guest();
$guestApi->setDi($harness->di);
$guestApi->setService($harness->saasService);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && preg_match('#^/servicesaas/return/(\d+)$#', $path, $matches) === 1) {
    $redirectUrl = $harness->saasService->resolveValidatedReturnUrl((int) $matches[1]);
    if ($redirectUrl === null) {
        header('Location: /order/service/manage/' . $matches[1], true, 302);

        return;
    }
    header('Location: ' . $redirectUrl, true, 302);

    return;
}

header('Content-Type: application/json');
$rawBody = file_get_contents('php://input') ?: '{}';
$decoded = json_decode($rawBody, true);
$payload = is_array($decoded) ? $decoded : [];

try {
    $result = match ($path) {
        '/api/guest/servicesaas/sync_catalog' => $guestApi->sync_catalog($payload),
        '/api/guest/servicesaas/checkout_session' => $guestApi->checkout_session($payload),
        '/api/guest/servicesaas/claim_entitlement' => $guestApi->claim_entitlement($payload),
        '/api/guest/servicesaas/account_event' => $guestApi->account_event($payload),
        '/api/guest/servicesaas/invoice_return' => $guestApi->invoice_return($payload),
        default => throw new \RuntimeException('Unknown route: ' . $path, 404),
    };
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $exception) {
    $status = $exception->getCode() >= 400 && $exception->getCode() < 600 ? $exception->getCode() : 400;
    http_response_code($status);
    echo json_encode(['error' => ['message' => $exception->getMessage(), 'code' => $status]], JSON_THROW_ON_ERROR);
}
