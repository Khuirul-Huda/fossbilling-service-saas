<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Tests\Support;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Client\Entity\ClientGroup;
use Box\Mod\Client\Entity\ClientGroupMembership;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Box\Mod\Product\Entity\ProductCategory;
use Box\Mod\Product\Entity\ProductPayment;
use Box\Mod\Product\Entity\ProductPaymentPeriod;
use Box\Mod\Servicesaas\Client\ProvisioningClient;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Persistence\SaasSchemaManager;
use Box\Mod\Servicesaas\Persistence\ServiceSaasRepository;
use Box\Mod\Servicesaas\Service;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Pimple\Container;

final class DoctrineBillingHarness
{
    public readonly Connection $connection;
    public readonly EntityManager $em;
    public readonly ServiceSaasRepository $saasRepo;
    public readonly Container $di;
    public readonly Service $saasService;

    /** @var list<array{client_id: int, amount: float, description: string}> */
    public array $creditDeposits = [];

    private const array ENTITY_CLASSES = [
        Client::class,
        ClientGroup::class,
        ClientGroupMembership::class,
        Product::class,
        ProductCategory::class,
        ProductPayment::class,
        ProductPaymentPeriod::class,
        Order::class,
        Invoice::class,
        PayGateway::class,
        Transaction::class,
    ];

    public function __construct(string $remoteBaseUrl, ?string $dbPath = null, ?\Closure $httpTransport = null)
    {
        $this->connection = SaasTestContext::createSqliteConnection($dbPath);
        $this->em = $this->createEntityManager($this->connection, $dbPath === null);
        $this->saasRepo = new ServiceSaasRepository($this->connection);
        $registry = SaasTestContext::createRegistry($remoteBaseUrl);
        $client = new ProvisioningClient(new EncryptedChannel(), $registry, $httpTransport);
        $this->saasService = new Service($this->saasRepo, $client, $registry);
        $this->di = $this->buildContainer($remoteBaseUrl);
        $this->saasService->setDi($this->di);
    }

    public function seedSaasProduct(string $planId, float $price, string $period = '1M'): Product
    {
        $product = (new Product())
            ->setTitle('CloudApp ' . $planId)
            ->setType('saas')
            ->setUnit('month')
            ->setConfig([
                'app_id' => 'cloudapp',
                'plan_id' => $planId,
                'plan_metadata' => ['storage_bytes' => 214_748_364_800, 'period' => $period],
                'period' => $period,
                'price' => $price,
            ]);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    public function seedClient(string $email): Client
    {
        $client = (new Client())
            ->setEmail(strtolower(trim($email)))
            ->setFirstName('Test')
            ->setLastName('User')
            ->setCurrency('IDR');
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    public function createTransactionForInvoice(Invoice $invoice): Transaction
    {
        $tx = (new Transaction())
            ->setInvoice($invoice)
            ->setStatus(Transaction::STATUS_RECEIVED);
        $this->em->persist($tx);
        $this->em->flush();

        return $tx;
    }

    private function createEntityManager(Connection $connection, bool $createSchema): EntityManager
    {
        $entityPaths = array_values(array_unique(array_map(
            static fn (string $cls): string => dirname((string) (new \ReflectionClass($cls))->getFileName()),
            self::ENTITY_CLASSES,
        )));

        $config = ORMSetup::createAttributeMetadataConfig($entityPaths, true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('ServicesaasTestProxies');
        }
        $em = new EntityManager($connection, $config);

        if ($createSchema || !$connection->createSchemaManager()->tablesExist(['client_order'])) {
            $metadata = array_map(static fn (string $cls) => $em->getClassMetadata($cls), self::ENTITY_CLASSES);
            (new SchemaTool($em))->createSchema($metadata);
            (new SaasSchemaManager($connection))->ensureTableExists();
        }

        return $em;
    }

    private function buildContainer(string $remoteBaseUrl): Container
    {
        $di = new Container();
        $di['em'] = $this->em;
        $di['mod_config'] = $di->protect(static fn (string $mod): array => match ($mod) {
            'servicesaas' => [
                'apps' => [[
                    'app_id' => 'cloudapp',
                    'name' => 'CloudApp Storage',
                    'base_url' => $remoteBaseUrl,
                    'shared_secret' => SaasTestContext::TEST_SECRET,
                    'allowed_redirect_hosts' => ['app.cloudapp.test', 'cloudapp.test'],
                ]],
            ],
            'order' => ['order_renewal_logic' => 'from_greater'],
            default => [],
        });

        $harness = $this;
        $services = [
            'client' => $this->makeClientFake($harness),
            'order' => $this->makeOrderFake($harness),
            'invoice' => $this->makeInvoiceFake($harness),
            'servicesaas' => $this->saasService,
        ];
        $di['mod_service'] = $di->protect(static fn (string $name): object => $services[strtolower($name)]
            ?? throw new \RuntimeException('Unknown test service: ' . $name));

        return $di;
    }

    private function makeClientFake(self $harness): object
    {
        return new class ($harness) {
            public function __construct(private readonly DoctrineBillingHarness $h)
            {
            }

            /** @param array<string, mixed> $data */
            public function adminCreateClient(array $data): int
            {
                $client = $this->h->seedClient((string) ($data['email'] ?? 'guest@example.com'));

                return (int) $client->getId();
            }

            /** @param array<string, mixed> $data */
            public function addFunds(Client $client, float $amount, string $desc, array $data = []): bool
            {
                $this->h->creditDeposits[] = ['client_id' => (int) $client->getId(), 'amount' => $amount, 'description' => $desc];

                return true;
            }
        };
    }

    private function makeOrderFake(self $harness): object
    {
        return new class ($harness) {
            public function __construct(private readonly DoctrineBillingHarness $h)
            {
            }

            /** @param array<string, mixed> $data */
            public function createOrder(Client $client, Product $product, array $data): int
            {
                $cfg = is_array($data['config'] ?? null) ? $data['config'] : [];
                $prodCfg = $product->getConfig() ?? [];
                $period = (string) ($data['period'] ?? $cfg['period'] ?? $prodCfg['period'] ?? '1M');
                $price = isset($data['price'])
                    ? sprintf('%.2f', (float) $data['price'])
                    : $this->resolveProductPrice($product, $period, $prodCfg);

                $order = (new Order())
                    ->setClientId((int) $client->getId())
                    ->setProductId((int) $product->getId())
                    ->setTitle((string) ($product->getTitle() ?? 'SaaS Plan'))
                    ->setCurrency('IDR')
                    ->setServiceType('servicesaas')
                    ->setPeriod($period)
                    ->setPrice($price)
                    ->setStatus(Order::STATUS_PENDING_SETUP)
                    ->setConfig($cfg);

                $this->h->em->persist($order);
                $this->h->em->flush();

                $this->h->saasService->create($order);
                $invoice = $this->h->di['mod_service']('invoice')->generateForOrder($order);
                $this->setUnpaidInvoice($order, $invoice);

                return (int) $order->getId();
            }

            public function setUnpaidInvoice(Order $order, Invoice $invoice): void
            {
                $order->setUnpaidInvoiceId((int) $invoice->getId());
                $this->h->em->flush();
            }

            /** @param array<string, mixed> $prodCfg */
            private function resolveProductPrice(Product $product, string $period, array $prodCfg): string
            {
                $pp = $product->getProductPayment();
                if ($pp instanceof ProductPayment) {
                    if ($pp->getType() === ProductPayment::FREE) {
                        return '0.00';
                    }
                    if ($pp->getType() === ProductPayment::ONCE) {
                        return sprintf('%.2f', $pp->getOncePrice());
                    }
                    $periodEntity = $pp->getPeriod($period);
                    if ($periodEntity !== null && $periodEntity->isEnabled()) {
                        return sprintf('%.2f', $periodEntity->getPrice());
                    }
                }

                return sprintf('%.2f', (float) ($prodCfg['price'] ?? 150000.0));
            }
        };
    }

    private function makeInvoiceFake(self $harness): object
    {
        return new class ($harness) {
            public function __construct(private readonly DoctrineBillingHarness $h)
            {
            }

            public function generateForOrder(Order $order): Invoice
            {
                $client = $this->h->em->getRepository(Client::class)->find((int) $order->getClientId());
                $invoice = (new Invoice())
                    ->setClientId((int) $order->getClientId())
                    ->setCurrency((string) ($order->getCurrency() ?? 'IDR'))
                    ->setStatus(Invoice::STATUS_UNPAID)
                    ->setIssued(true)
                    ->setBaseIncome((string) ($order->getPrice() ?? '150000.00'))
                    ->setBuyerEmail($client?->getEmail() ?? '')
                    ->setHash(bin2hex(random_bytes(8)));

                $this->h->em->persist($invoice);
                $this->h->em->flush();

                return $invoice;
            }

            /** @param array<string, mixed> $data */
            public function issueInvoice(Invoice $invoice, array $data = []): bool
            {
                $invoice->setIssued(true);
                $this->h->em->flush();

                return true;
            }

            public function isInvoiceTypeDeposit(Invoice $invoice): bool
            {
                return false;
            }

            public function payInvoiceWithCredits(Invoice $invoice): bool
            {
                $invoice->setStatus(Invoice::STATUS_PAID)->setPaidAt(new \DateTime());
                $orders = $this->h->em->getRepository(Order::class)->findBy(['unpaidInvoiceId' => $invoice->getId()]);
                foreach ($orders as $order) {
                    $order->setStatus(Order::STATUS_ACTIVE)->setUnpaidInvoiceId(null);
                    $this->h->em->flush();
                    $this->h->saasService->activate($order);
                }
                $this->h->em->flush();

                return true;
            }
        };
    }
}
