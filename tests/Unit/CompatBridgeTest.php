<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Compat.php';

use Box\Mod\Servicesaas\Bridge\InvoiceCompat;
use Box\Mod\Servicesaas\Compat\RuntimeCompat;
use Doctrine\DBAL\DriverManager;

it('resolves $di[dbal] and client IDs cleanly for 0.8.9 without EntityManager', function (): void {
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $di = new Pimple\Container();
    $di['dbal'] = $conn;

    $doctrineLikeClient = new class () {
        public function getId(): int
        {
            return 41;
        }
    };
    $redbeanClient = (object) ['id' => 92];

    expect(RuntimeCompat::dbal($di))->toBe($conn)
        ->and(RuntimeCompat::clientId($doctrineLikeClient))->toBe(41)
        ->and(RuntimeCompat::clientId($redbeanClient))->toBe(92)
        ->and(RuntimeCompat::clientId(null))->toBeNull();

    if (class_exists(\Symfony\Component\HttpFoundation\Response::class)) {
        $resp = RuntimeCompat::htmlResponse('ok', 403);
        expect($resp->getStatusCode())->toBe(403)
            ->and($resp->getContent())->toBe('ok');
    }
});

it('handles RedBean Invoice, InvoiceItem upgrade pricing, cancel, and gateway checks when em is absent', function (): void {
    if (!class_exists(\Payment_Adapter_UnitFakeManual::class, false)) {
        eval('class Payment_Adapter_UnitFakeManual { public static function requiresManualApproval(): bool { return true; } }');
    }

    $rbInvoice = (object) ['id' => 11, 'hash' => 'rbhash11', 'status' => 'unpaid', 'base_income' => '9900'];
    $rbItem = (object) ['id' => 21, 'title' => 'Old Plan', 'price' => 9900.0, 'period' => '1M'];
    $rbGateway = (object) ['id' => 7, 'gateway' => 'UnitFakeManual', 'enabled' => 1];

    $db = new class ($rbInvoice, $rbItem, $rbGateway) {
        /** @var list<object> */
        public array $stored = [];

        public function __construct(
            private readonly object $invoice,
            private readonly object $item,
            private readonly object $gateway,
        ) {
        }

        public function load(string $model, int $id): ?object
        {
            return match ($model) {
                'Invoice' => $id === 11 ? $this->invoice : null,
                'PayGateway' => $id === 7 ? $this->gateway : null,
                default => null,
            };
        }

        /** @param array<int, mixed> $params */
        public function findOne(string $model, string $sql, array $params): ?object
        {
            return $model === 'Invoice' && ($params[0] ?? '') === 'rbhash11' ? $this->invoice : null;
        }

        /** @param array<int, mixed> $params @return list<object> */
        public function find(string $model, string $sql, array $params): array
        {
            return $model === 'InvoiceItem' && ($params[0] ?? 0) === 11 ? [$this->item] : [];
        }

        public function store(object $bean): int
        {
            $this->stored[] = $bean;

            return (int) ($bean->id ?? 1);
        }
    };

    $di = new Pimple\Container();
    $di['db'] = $db;

    expect(InvoiceCompat::findById($di, 11))->toBe($rbInvoice)
        ->and(InvoiceCompat::findByHash($di, 'rbhash11'))->toBe($rbInvoice)
        ->and(InvoiceCompat::isUnpaid($rbInvoice))->toBeTrue()
        ->and(InvoiceCompat::hash($rbInvoice))->toBe('rbhash11')
        ->and(InvoiceCompat::baseIncome($rbInvoice))->toBe(9900.0);

    $orderLike = new class () {
        public function getTitle(): string
        {
            return 'Perintis';
        }

        public function getPrice(): string
        {
            return '9900';
        }

        public function getPeriod(): string
        {
            return '1M';
        }
    };

    InvoiceCompat::applyPendingUpgrade($di, $rbInvoice, [
        'order' => $orderLike,
        'pending' => ['title' => 'Standar 100GB', 'price' => 29900.0, 'period' => '1M'],
    ]);

    expect($rbInvoice->base_income)->toBe('29900')
        ->and($rbItem->title)->toBe('Standar 100GB')
        ->and($rbItem->price)->toBe(29900.0);

    InvoiceCompat::cancelUnpaid($di, $rbInvoice, 'Superseded');
    expect($rbInvoice->status)->toBe('canceled')
        ->and(InvoiceCompat::isEnabledManualGateway($di, 7))->toBeTrue()
        ->and($db->stored)->toHaveCount(3);
});
