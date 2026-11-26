<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

/**
 * Summary counts and mapped product IDs returned after synchronizing an app catalog.
 */
final readonly class CatalogSyncResult
{
    /**
     * @param array<string, int> $products
     */
    public function __construct(
        public int $categoryId,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $archived,
        public array $products,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => 'ok',
            'category_id' => $this->categoryId,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'archived' => $this->archived,
            'products' => $this->products,
        ];
    }
}
