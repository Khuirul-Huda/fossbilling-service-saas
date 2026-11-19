<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Controller;

require_once dirname(__DIR__) . '/src/Compat.php';

use Box\Mod\Servicesaas\Compat\InjectionAwareInterface;
use Box\Mod\Servicesaas\Compat\RuntimeCompat;
use Box\Mod\Servicesaas\Service;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated admin endpoint to view pending manual payment proofs before post-provisioning purge.
 */
class Admin implements InjectionAwareInterface
{
    private const int HTTP_FORBIDDEN = 403;
    private const int HTTP_NOT_FOUND = 404;

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(mixed &$app): void
    {
        $app->get('/servicesaas/proof/:invoice_id', 'get_proof', ['invoice_id' => '[0-9]+'], static::class);
    }

    public function get_proof(mixed $app, int|string $invoice_id): Response
    {
        if (!$this->isAdminAuthenticated()) {
            return RuntimeCompat::htmlResponse('Admin authentication required.', self::HTTP_FORBIDDEN);
        }

        $service = $this->resolveService();
        if ($service === null) {
            return RuntimeCompat::htmlResponse('', self::HTTP_NOT_FOUND);
        }

        $proof = $service->getProofStorage()->readProof((int) $invoice_id);
        if ($proof === null) {
            return RuntimeCompat::htmlResponse('Payment proof has already been purged or does not exist.', self::HTTP_NOT_FOUND);
        }

        return RuntimeCompat::binaryResponse($proof['path'], $proof['mime'], $proof['filename']);
    }

    private function isAdminAuthenticated(): bool
    {
        if ($this->di === null || !isset($this->di['is_admin_logged']) || !$this->di['is_admin_logged']) {
            return false;
        }

        try {
            $staff = $this->di['mod_service']('staff');
            if (is_object($staff) && method_exists($staff, 'checkPermissionsAndThrowException')) {
                $staff->checkPermissionsAndThrowException('servicesaas', null);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function resolveService(): ?Service
    {
        if ($this->di === null) {
            return null;
        }
        $service = $this->di['mod_service']('servicesaas');

        return $service instanceof Service ? $service : null;
    }
}
