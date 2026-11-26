<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Product\Entity\Product;
use Box\Mod\Product\Entity\ProductCategory;
use Box\Mod\Servicesaas\Client\AppConfig;
use Box\Mod\Servicesaas\Client\AppRegistry;
use Box\Mod\Servicesaas\Contract\CatalogSyncInterface;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Crypto\EnvelopeDto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronizes encrypted external app catalogs into FOSSBilling SaaS products cleanly and idempotently.
 */
final class CatalogSyncBridge implements CatalogSyncInterface
{
    private readonly CatalogProductMapper $mapper;

    public function __construct(
        private readonly EncryptedChannel $channel,
        private readonly AppRegistry $registry,
        private readonly EntityManagerInterface $em,
        ?CatalogProductMapper $mapper = null,
    ) {
        $this->mapper = $mapper ?? new CatalogProductMapper();
    }

    /**
     * Decrypts a sync_catalog request envelope, applies catalog updates, and returns an encrypted reply.
     *
     * @param array<string, mixed> $rawEnvelope
     *
     * @return array{v: int, app_id: string, nonce: string, payload: string}
     */
    public function handleEncryptedRequest(array $rawEnvelope): array
    {
        $envelope = EnvelopeDto::fromArray($rawEnvelope);
        $app = $this->registry->get($envelope->appId);
        $decrypted = $this->channel->open($envelope, $app->sharedSecret);
        $request = CatalogSyncRequest::fromDecryptedPayload($decrypted);

        if ($request->appId !== $envelope->appId) {
            throw new \InvalidArgumentException('Envelope app_id does not match catalog sync payload app_id.');
        }

        $result = $this->sync($app, $request);

        return $this->channel->seal($app->appId, $result->toArray(), $app->sharedSecret)->toArray();
    }

    public function sync(AppConfig $app, CatalogSyncRequest $request): CatalogSyncResult
    {
        if (!$app->allowCatalogSync) {
            throw new \RuntimeException(sprintf('Catalog sync is disabled for SaaS app "%s".', $app->appId));
        }

        $category = $this->resolveCategory($app, $request->categoryTitle);
        $context = new CatalogApplyContext($app, $category);
        $existingProducts = $this->loadAppProducts($app->appId);

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'archived' => 0];
        $mappedProducts = [];
        $matchedIds = [];

        foreach ($request->items as $item) {
            $product = $this->findProductForItem($existingProducts, $item->planId) ?? new Product();
            $status = $this->mapper->applyToProduct($product, $item, $context);
            $this->persistProductAndPayment($product);

            $counts[$status]++;
            $productId = (int) $product->getId();
            $mappedProducts[$item->planId] = $productId;
            $matchedIds[$productId] = true;
        }

        if ($request->mode === CatalogSyncRequest::MODE_UPSERT_AND_ARCHIVE) {
            $counts['archived'] = $this->archiveRemovedManagedProducts($existingProducts, $matchedIds);
        }

        $this->em->flush();

        return new CatalogSyncResult(
            (int) $category->getId(),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['archived'],
            $mappedProducts,
        );
    }

    private function resolveCategory(AppConfig $app, ?string $preferredTitle): ProductCategory
    {
        $title = $preferredTitle ?? sprintf('SaaS Plans — %s', $app->name);
        $repo = $this->em->getRepository(ProductCategory::class);
        $existing = $repo->findOneBy(['title' => $title]);
        if ($existing instanceof ProductCategory) {
            return $existing;
        }

        $category = (new ProductCategory())
            ->setTitle($title)
            ->setDescription(sprintf('Auto-synced SaaS products for %s (%s).', $app->name, $app->appId));
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * @return list<Product>
     */
    private function loadAppProducts(string $appId): array
    {
        $all = $this->em->getRepository(Product::class)->findBy(['type' => 'saas']);
        $scoped = [];

        foreach ($all as $product) {
            $config = $product->getConfig() ?? [];
            if (($config['app_id'] ?? '') === $appId) {
                $scoped[] = $product;
            }
        }

        return $scoped;
    }

    /**
     * @param list<Product> $products
     */
    private function findProductForItem(array $products, string $planId): ?Product
    {
        foreach ($products as $product) {
            if (PlanIdResolver::matchesProductExact($product->getConfig() ?? [], $planId)) {
                return $product;
            }
        }

        return null;
    }

    private function persistProductAndPayment(Product $product): void
    {
        $payment = $product->getProductPayment();
        if ($payment !== null && $payment->getId() === null) {
            $this->em->persist($payment);
        }
        $this->em->persist($product);
        $this->em->flush();
    }

    /**
     * @param list<Product>      $existingProducts
     * @param array<int, true>   $matchedIds
     */
    private function archiveRemovedManagedProducts(array $existingProducts, array $matchedIds): int
    {
        $archived = 0;
        foreach ($existingProducts as $product) {
            $id = (int) $product->getId();
            if (isset($matchedIds[$id])) {
                continue;
            }
            $config = $product->getConfig() ?? [];
            if (($config['sync_managed'] ?? false) !== true || !$product->isActive()) {
                continue;
            }

            $product->setActive(false)->setStatus('disabled');
            $this->em->persist($product);
            $archived++;
        }

        return $archived;
    }
}
