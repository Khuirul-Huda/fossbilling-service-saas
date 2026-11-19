<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Compat.php';

use Box\Mod\Client\Entity\Client;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Bridge\InvoiceCompat;
use Box\Mod\Servicesaas\Compat\RuntimeCompat;
use Doctrine\DBAL\DriverManager;

it('resolves $di[dbal], client IDs, and HTML responses cleanly for 0.8.9 without EntityManager', function (): void {
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $di = new Pimple\Container();
    $di['dbal'] = $conn;

    $doctrineClient = new Client();
    (new \ReflectionProperty(Client::class, 'id'))->setValue($doctrineClient, 41);
    $redbeanClient = (object) ['id' => 92];

    expect(RuntimeCompat::dbal($di))->toBe($conn)
        ->and(RuntimeCompat::clientId($doctrineClient))->toBe(41)
        ->and(RuntimeCompat::clientId($redbeanClient))->toBe(92)
        ->and(RuntimeCompat::clientId(null))->toBeNull();

    $resp = RuntimeCompat::htmlResponse('ok', 403);
    expect($resp->getStatusCode())->toBe(403)
        ->and($resp->getContent())->toBe('ok');
});

it('handles RedBean Invoice, InvoiceItem upgrade pricing, cancel, and gateway checks when em is absent', function (): void {
    $rbInvoice = (object) ['id' => 11, 'hash' => 'rbhash11', 'status' => 'unpaid', 'base_income' => '9900'];
    $rbItem = (object) ['id' => 21, 'title' => 'Old Plan', 'price' => 9900.0, 'period' => '1M'];
    $rbGateway = (object) ['id' => 7, 'gateway' => 'ManualTransfer', 'enabled' => 1];

    $db = Mockery::mock();
    $db->shouldReceive('load')->with('Invoice', 11)->andReturn($rbInvoice);
    $db->shouldReceive('findOne')->with('Invoice', 'hash = ?', ['rbhash11'])->andReturn($rbInvoice);
    $db->shouldReceive('store')->with($rbInvoice)->twice();
    $db->shouldReceive('find')->with('InvoiceItem', 'invoice_id = ?', [11])->andReturn([$rbItem]);
    $db->shouldReceive('store')->with($rbItem)->once();
    $db->shouldReceive('load')->with('PayGateway', 7)->andReturn($rbGateway);

    $di = new Pimple\Container();
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(static fn (): object => new stdClass());

    expect(InvoiceCompat::findById($di, 11))->toBe($rbInvoice)
        ->and(InvoiceCompat::findByHash($di, 'rbhash11'))->toBe($rbInvoice)
        ->and(InvoiceCompat::isUnpaid($rbInvoice))->toBeTrue()
        ->and(InvoiceCompat::hash($rbInvoice))->toBe('rbhash11')
        ->and(InvoiceCompat::baseIncome($rbInvoice))->toBe(9900.0);

    $order = (new Order())->setTitle('Perintis')->setPrice('9900')->setPeriod('1M');
    InvoiceCompat::applyPendingUpgrade($di, $rbInvoice, [
        'order' => $order,
        'pending' => ['title' => 'Standar 100GB', 'price' => 29900.0, 'period' => '1M'],
    ]);

    expect($rbInvoice->base_income)->toBe('29900')
        ->and($rbItem->title)->toBe('Standar 100GB')
        ->and($rbItem->price)->toBe(29900.0);

    InvoiceCompat::cancelUnpaid($di, $rbInvoice, 'Superseded');
    expect($rbInvoice->status)->toBe('canceled');

    require_once dirname(__DIR__, 4) . '/library/Payment/Adapter/ManualTransfer/ManualTransfer.php';
    expect(InvoiceCompat::isEnabledManualGateway($di, 7))->toBeTrue();
});
