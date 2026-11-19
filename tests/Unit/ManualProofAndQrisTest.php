<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Box\Mod\Servicesaas\Bridge\ManualPaymentHandler;
use Box\Mod\Servicesaas\Bridge\ManualProofStorage;
use Box\Mod\Servicesaas\Bridge\PrivateQrisStore;
use Box\Mod\Servicesaas\Bridge\UploadedFileHelper;
use Box\Mod\Servicesaas\Service;
use Doctrine\DBAL\DriverManager;
use Pimple\Container;

function makeTempProofDir(): string
{
    $dir = sys_get_temp_dir() . '/saas_proof_test_' . bin2hex(random_bytes(5));
    mkdir($dir, 0750, true);

    return $dir;
}

function makeSamplePngFile(string $dir, string $name = 'proof.png'): string
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+X2kAAAAASUVORK5CYII=');
    $path = $dir . '/' . $name;
    file_put_contents($path, $png);

    return $path;
}

function makeTempFile(string $dir, string $name, string $bytes): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, $bytes);

    return $path;
}

/** @return array{error: int, tmp_name: string, name: string, size: int, _allow_local_copy: bool} */
function makeUploadArray(string $path): array
{
    return [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $path,
        'name' => basename($path),
        'size' => (int) filesize($path),
        '_allow_local_copy' => true,
    ];
}

function cleanDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $dir . '/' . $item;
        is_dir($target) ? cleanDirRecursive($target) : @unlink($target);
    }
    @rmdir($dir);
}

it('validates MIME and size limits before storing private payment proof and purges by order', function (): void {
    $dir = makeTempProofDir();
    $srcDir = makeTempProofDir();
    $storage = new ManualProofStorage($dir);

    try {
        expect(fn (): array => $storage->storeUploadedProof(42, null))
            ->toThrow(\InvalidArgumentException::class, 'wajib diunggah');

        $badText = makeTempFile($srcDir, 'fake.png', 'plain text not an image');
        expect(fn (): array => $storage->storeUploadedProof(42, makeUploadArray($badText)))
            ->toThrow(\InvalidArgumentException::class, 'Format file');

        $oversized = makeTempFile($srcDir, 'big.pdf', "%PDF-1.4\n" . str_repeat('A', UploadedFileHelper::MAX_FILE_BYTES + 32));
        expect(fn (): array => $storage->storeUploadedProof(42, makeUploadArray($oversized)))
            ->toThrow(\InvalidArgumentException::class, '5 MB');

        $validPng = makeSamplePngFile($srcDir);
        $meta = $storage->storeUploadedProof(42, makeUploadArray($validPng));

        $loaded = $storage->readProof(42);
        expect($loaded)->not->toBeNull()
            ->and($loaded['filename'])->toBe($meta['filename'])
            ->and($loaded['mime'])->toBe('image/png')
            ->and(is_file($dir . '/.htaccess'))->toBeTrue()
            ->and(file_get_contents($dir . '/.htaccess'))->toContain('Deny from all');

        $dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, rel_id INTEGER, type TEXT)');
        $dbal->insert('invoice_item', ['invoice_id' => 42, 'rel_id' => 99, 'type' => 'order']);

        $listed = $storage->findExistingProofsForOrder($dbal, 99);
        expect($listed)->toHaveCount(1)
            ->and($listed[0]['invoice_id'])->toBe(42);

        $purgedCount = $storage->purgeProofsForOrder($dbal, 99);
        expect($purgedCount)->toBe(1)
            ->and($storage->readProof(42))->toBeNull()
            ->and(is_file($loaded['path']))->toBeFalse()
            ->and($storage->findExistingProofsForOrder($dbal, 99))->toBe([]);
    } finally {
        cleanDirRecursive($dir);
        cleanDirRecursive($srcDir);
    }
});

it('stores private QRIS fallback image and verifies HMAC signed URL params', function (): void {
    $dir = makeTempProofDir();
    $srcDir = makeTempProofDir();
    $store = new PrivateQrisStore($dir);

    try {
        $pdf = makeTempFile($srcDir, 'qris.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        expect(fn (): array => $store->store(makeUploadArray($pdf)))
            ->toThrow(\InvalidArgumentException::class, 'Format file');

        $validPng = makeSamplePngFile($srcDir, 'qris.png');
        $meta = $store->store(makeUploadArray($validPng));
        expect($meta['filename'])->toBe('qris-fallback.png')
            ->and($store->read())->not->toBeNull();

        $params = $store->signParams('invHash123', 3600);
        expect($store->verifySignature($params['invoice'], $params['exp'], $params['sig']))->toBeTrue()
            ->and($store->verifySignature('tampered', $params['exp'], $params['sig']))->toBeFalse()
            ->and($store->verifySignature($params['invoice'], time() - 10, $params['sig']))->toBeFalse()
            ->and($store->verifySignature($params['invoice'], $params['exp'], str_repeat('0', 64)))->toBeFalse();

        $store->delete();
        expect($store->read())->toBeNull();
    } finally {
        cleanDirRecursive($dir);
        cleanDirRecursive($srcDir);
    }
});

it('enforces Antispam CAPTCHA, persists proof, and adds admin proof URL to ManualPaymentHandler note', function (): void {
    $dir = makeTempProofDir();
    $srcDir = makeTempProofDir();

    try {
        $dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $dbal->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, hash TEXT, status TEXT, approved INTEGER)');
        $dbal->executeStatement('CREATE TABLE pay_gateway (id INTEGER PRIMARY KEY, gateway TEXT, enabled INTEGER)');
        $dbal->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, rel_id INTEGER, type TEXT)');
        $dbal->insert('invoice', ['id' => 55, 'hash' => 'inv55hash', 'status' => 'unpaid', 'approved' => 0]);
        $dbal->insert('pay_gateway', ['id' => 7, 'gateway' => 'DummyManualGateway', 'enabled' => 1]);

        if (!class_exists('Payment_Adapter_DummyManualGateway')) {
            eval('class Payment_Adapter_DummyManualGateway { public static function requiresManualApproval(): bool { return true; } }');
        }

        $capturedTx = null;
        $txService = new class ($capturedTx) {
            public function __construct(private mixed &$capturedTx)
            {
            }

            /** @param array<string, mixed> $data */
            public function create(array $data): int
            {
                $this->capturedTx = $data;

                return 701;
            }
        };

        $antispamService = new class () {
            /** @param array<string, mixed> $params */
            public function checkCaptcha(array $params): void
            {
                if (($params['g-recaptcha-response'] ?? '') !== 'valid-token') {
                    throw new \RuntimeException('CAPTCHA verification failed.');
                }
            }
        };

        $di = new Container();
        $di['dbal'] = $dbal;
        $di['db'] = new class ($dbal) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $dbal)
            {
            }

            /** @param array<int, mixed> $params */
            public function findOne(string $beanType, string $sql, array $params): ?object
            {
                $row = match ($beanType) {
                    'Invoice' => $this->dbal->fetchAssociative('SELECT * FROM invoice WHERE hash = ?', [$params[0] ?? '']),
                    default => false,
                };

                return is_array($row) ? (object) $row : null;
            }

            public function load(string $beanType, int $id): ?object
            {
                $table = $beanType === 'PayGateway' ? 'pay_gateway' : 'invoice';
                $row = $this->dbal->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$id]);

                return is_array($row) ? (object) $row : null;
            }
        };
        $di['url'] = new class () {
            public function link(string $path): string
            {
                return 'https://billing.test/' . ltrim($path, '/');
            }

            public function adminLink(string $path): string
            {
                return 'https://billing.test/admin/' . ltrim($path, '/');
            }
        };
        $modServiceResolver = static fn (string $name, ?string $sub = null): object => match (strtolower($name)) {
            'antispam' => $antispamService,
            'invoice' => $txService,
            default => throw new \RuntimeException('Unexpected service: ' . $name),
        };
        $di['mod_service'] = method_exists($di, 'protect') ? $di->protect($modServiceResolver) : $modServiceResolver;

        $saasService = new Service();
        $saasService->setDi($di);
        $saasService->setProofStorage(new ManualProofStorage($dir));

        $handler = new ManualPaymentHandler($di, $saasService);

        expect(fn (): array => $handler->handleSubmission([
            'invoice_hash' => 'inv55hash',
            'gateway_id' => 7,
            'sender_name' => 'Budi',
            'g-recaptcha-response' => 'bad',
        ]))->toThrow(\RuntimeException::class, 'CAPTCHA verification failed');

        $validPng = makeSamplePngFile($srcDir);
        $result = $handler->handleSubmission([
            'invoice_hash' => 'inv55hash',
            'gateway_id' => 7,
            'payment_channel' => 'QRIS',
            'sender_name' => 'Budi Santoso',
            'g-recaptcha-response' => 'valid-token',
        ], makeUploadArray($validPng));

        expect($result['invoice_hash'])->toBe('inv55hash')
            ->and($result['redirect_url'])->toBe('https://billing.test/invoice/inv55hash')
            ->and($capturedTx['note'])->toContain('Bukti: https://billing.test/admin/servicesaas/proof/55')
            ->and($saasService->getProofStorage()->readProof(55))->not->toBeNull();
    } finally {
        cleanDirRecursive($dir);
        cleanDirRecursive($srcDir);
    }
});
