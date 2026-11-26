<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Contract;

use Box\Mod\Servicesaas\Bridge\CatalogSyncRequest;
use Box\Mod\Servicesaas\Bridge\CatalogSyncResult;
use Box\Mod\Servicesaas\Client\AppConfig;

/**
 * Contract for synchronizing external application pricing catalogs into FOSSBilling products.
 */
interface CatalogSyncInterface
{
    public function sync(AppConfig $app, CatalogSyncRequest $request): CatalogSyncResult;
}
