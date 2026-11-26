<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

/**
 * Validated request payload for syncing external application pricing into FOSSBilling.
 */
final readonly class CatalogSyncRequest
{
    public const string MODE_UPSERT = 'upsert';
    public const string MODE_UPSERT_AND_ARCHIVE = 'upsert_and_archive';

    public string $appId;
    public ?string $categoryTitle;
    public string $mode;
    /** @var list<CatalogItemSpec> */
    public array $items;

    /**
     * @param list<CatalogItemSpec> $items
     */
    private function __construct(
        string $appId,
        ?string $categoryTitle,
        string $mode,
        array $items,
    ) {
        $this->appId = $appId;
        $this->categoryTitle = $categoryTitle;
        $this->mode = $mode;
        $this->items = $items;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromDecryptedPayload(array $data): self
    {
        $appId = trim((string) ($data['app_id'] ?? $data['appId'] ?? ''));
        if ($appId === '') {
            throw new \InvalidArgumentException('Field "app_id" is required in catalog sync payload.');
        }

        $mode = strtolower(trim((string) ($data['mode'] ?? self::MODE_UPSERT)));
        if (!in_array($mode, [self::MODE_UPSERT, self::MODE_UPSERT_AND_ARCHIVE], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported catalog sync mode "%s".', $mode));
        }

        $rawPlans = $data['plans'] ?? $data['items'] ?? null;
        if (!is_array($rawPlans)) {
            throw new \InvalidArgumentException('Field "plans" must be a list of catalog items.');
        }

        $items = [];
        $seen = [];
        foreach ($rawPlans as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Each catalog entry must be an object.');
            }
            $spec = CatalogItemSpec::fromArray($entry);
            $lowerId = strtolower($spec->planId);
            if (isset($seen[$lowerId])) {
                throw new \InvalidArgumentException(sprintf('Duplicate plan_id "%s" in catalog sync payload.', $spec->planId));
            }
            $seen[$lowerId] = true;
            $items[] = $spec;
        }

        $rawCategory = $data['category_title'] ?? null;
        $categoryTitle = is_string($rawCategory) && trim($rawCategory) !== '' ? trim($rawCategory) : null;

        return new self($appId, $categoryTitle, $mode, $items);
    }
}
