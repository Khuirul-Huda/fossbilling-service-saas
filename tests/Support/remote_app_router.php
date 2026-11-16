<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Box\Mod\Servicesaas\Tests\Support\SaasTestContext;

$dbPath = getenv('SERVICESAAS_REMOTE_DB') ?: '';
$appId = getenv('SERVICESAAS_REMOTE_APP_ID') ?: 'cloudapp';
$secret = getenv('SERVICESAAS_REMOTE_SECRET') ?: SaasTestContext::TEST_SECRET;
$fbUrl = rtrim(getenv('SERVICESAAS_REMOTE_FB_URL') ?: '', '/');
if ($dbPath === '') {
    http_response_code(500);
    echo json_encode(['error' => 'SERVICESAAS_REMOTE_DB is required']);

    return;
}

$pdo = new \PDO('sqlite:' . $dbPath);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS app_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, plan_id TEXT NOT NULL, storage_bytes INTEGER NOT NULL, expiry_micro INTEGER NOT NULL, ext_tx_id TEXT NOT NULL, provider TEXT NOT NULL)');

$channel = new EncryptedChannel();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawBody = file_get_contents('php://input') ?: '{}';
$body = json_decode($rawBody, true);
$data = is_array($body) ? $body : [];

header('Content-Type: application/json');

function respondJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function findRemoteUser(\PDO $pdo, string $email, int $userId): ?array
{
    if ($userId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM app_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    $stmt = $pdo->prepare('SELECT * FROM app_users WHERE LOWER(email) = :email');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

if ($method === 'POST' && $path === '/_test/users') {
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $userId = (int) ($data['user_id'] ?? 0);
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO app_users (id, email, plan_id, storage_bytes, expiry_micro, ext_tx_id, provider) VALUES (:id, :email, :plan, 0, 0, :tx, :prov)');
    $stmt->execute(['id' => $userId > 0 ? $userId : null, 'email' => $email, 'plan' => 'free', 'tx' => 'none', 'prov' => '']);
    respondJson(201, ['user_id' => (int) ($userId > 0 ? $userId : $pdo->lastInsertId()), 'email' => $email]);

    return;
}

if ($method === 'GET' && preg_match('#^/_test/subscription/(\d+)$#', $path, $matches) === 1) {
    $user = findRemoteUser($pdo, '', (int) $matches[1]);
    respondJson($user !== null ? 200 : 404, $user ?? ['error' => 'Not found']);

    return;
}

if ($method === 'POST' && $path === '/_test/signup') {
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $stmt = $pdo->prepare('INSERT INTO app_users (email, plan_id, storage_bytes, expiry_micro, ext_tx_id, provider) VALUES (:email, :plan, 0, 0, :tx, :prov)');
    $stmt->execute(['email' => $email, 'plan' => 'free', 'tx' => 'none', 'prov' => '']);
    $newUserId = (int) $pdo->lastInsertId();

    $claimPayload = ['app_id' => $appId, 'external_user_id' => (string) $newUserId, 'email' => $email];
    $sealedClaim = $channel->seal($appId, $claimPayload, $secret)->toArray();
    $ch = curl_init($fbUrl . '/api/guest/servicesaas/claim_entitlement');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($sealedClaim, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $rawResp = curl_exec($ch);
    $decoded = is_string($rawResp) ? (json_decode($rawResp, true) ?: []) : [];
    $respEnv = $decoded['result'] ?? $decoded;
    $claim = $channel->open(EnvelopeDto::fromArray(is_array($respEnv) ? $respEnv : []), $secret);

    if (($claim['matched'] ?? false) === true) {
        $storageBytes = (int) ($claim['plan_metadata']['storage_bytes'] ?? 0);
        $txId = 'fb:' . (string) ($claim['order_id'] ?? 0);
        $upd = $pdo->prepare('UPDATE app_users SET plan_id = :plan, storage_bytes = :bytes, expiry_micro = :exp, ext_tx_id = :tx, provider = :prov WHERE id = :id');
        $upd->execute(['plan' => (string) $claim['plan_id'], 'bytes' => $storageBytes, 'exp' => (int) ($claim['expires_at_micro'] ?? 0), 'tx' => $txId, 'prov' => 'fossbilling', 'id' => $newUserId]);
    }
    respondJson(200, ['user_id' => $newUserId, 'matched' => (bool) ($claim['matched'] ?? false)]);

    return;
}

if ($method === 'POST' && $path === '/_test/trigger-checkout') {
    $user = findRemoteUser($pdo, (string) ($data['email'] ?? ''), (int) ($data['user_id'] ?? 0));
    if ($user === null) {
        respondJson(404, ['error' => 'User not found']);

        return;
    }
    $req = ['app_id' => $appId, 'external_user_id' => (string) $user['id'], 'email' => (string) $user['email'], 'plan_id' => (string) ($data['plan_id'] ?? ''), 'action' => (string) ($data['action'] ?? 'buy'), 'redirect_url' => (string) ($data['redirect_url'] ?? '')];
    $sealed = $channel->seal($appId, $req, $secret)->toArray();
    $ch = curl_init($fbUrl . '/api/guest/servicesaas/checkout_session');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($sealed, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $rawResp = curl_exec($ch);
    $decoded = is_string($rawResp) ? (json_decode($rawResp, true) ?: []) : [];
    $respEnv = $decoded['result'] ?? $decoded;
    respondJson(200, $channel->open(EnvelopeDto::fromArray(is_array($respEnv) ? $respEnv : []), $secret));

    return;
}

if ($method === 'POST' && $path === '/billing/notify/fossbilling') {
    try {
        $cmd = $channel->open(EnvelopeDto::fromArray($data), $secret);
    } catch (\Throwable $e) {
        respondJson(403, ['error' => $e->getMessage()]);

        return;
    }

    $user = findRemoteUser($pdo, (string) ($cmd['email'] ?? ''), (int) ($cmd['external_user_id'] ?? 0));
    if ($user === null) {
        respondJson(200, $channel->seal($appId, ['status' => 'pending_registration', 'order_id' => $cmd['order_id'] ?? 0], $secret)->toArray());

        return;
    }

    $action = (string) ($cmd['action'] ?? '');
    $orderId = (int) ($cmd['order_id'] ?? 0);
    if ($action === 'suspend' || $action === 'cancel') {
        $upd = $pdo->prepare('UPDATE app_users SET plan_id = :plan, storage_bytes = 0, expiry_micro = 0, ext_tx_id = :tx, provider = :prov WHERE id = :id');
        $upd->execute(['plan' => 'free', 'tx' => 'none', 'prov' => '', 'id' => (int) $user['id']]);
        respondJson(200, $channel->seal($appId, ['status' => 'downgraded', 'external_user_id' => (string) $user['id']], $secret)->toArray());

        return;
    }

    $storageBytes = (int) ($cmd['plan_metadata']['storage_bytes'] ?? 0);
    $upd = $pdo->prepare('UPDATE app_users SET plan_id = :plan, storage_bytes = :bytes, expiry_micro = :exp, ext_tx_id = :tx, provider = :prov WHERE id = :id');
    $upd->execute(['plan' => (string) ($cmd['plan_id'] ?? ''), 'bytes' => $storageBytes, 'exp' => (int) ($cmd['expires_at_micro'] ?? 0), 'tx' => 'fb:' . $orderId, 'prov' => 'fossbilling', 'id' => (int) $user['id']]);
    respondJson(200, $channel->seal($appId, ['status' => 'provisioned', 'order_id' => $orderId, 'external_user_id' => (string) $user['id'], 'expiry_time' => (int) ($cmd['expires_at_micro'] ?? 0)], $secret)->toArray());

    return;
}

respondJson(404, ['error' => 'Route not found']);
