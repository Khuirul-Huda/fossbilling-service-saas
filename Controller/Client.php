<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Controller;

use Box\Mod\Servicesaas\Service;
use FOSSBilling\Core\Container\InjectionAwareInterface;
use FOSSBilling\Core\Http\ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

class Client implements InjectionAwareInterface
{
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

    public function register(\FOSSBilling\Core\Http\App &$app): void
    {
        $app->get('/servicesaas/return/:order_id', 'get_return', ['order_id' => '[0-9]+'], static::class);
    }

    public function get_return(\FOSSBilling\Core\Http\App $app, int|string $order_id): Response
    {
        $orderId = (int) $order_id;
        $service = $this->di['mod_service']('servicesaas');
        if (!$service instanceof Service) {
            return (new ResponseFactory())->html('', self::HTTP_NOT_FOUND);
        }

        $clientId = $this->resolveLoggedInClientId();

        try {
            $redirectUrl = $service->resolveValidatedReturnUrl($orderId, $clientId);
        } catch (\Throwable) {
            return (new ResponseFactory())->html('', self::HTTP_NOT_FOUND);
        }

        if ($redirectUrl === null) {
            return $app->redirect('/order/service/manage/' . $orderId);
        }

        return $app->redirectUrl($redirectUrl, Response::HTTP_FOUND);
    }

    private function resolveLoggedInClientId(): ?int
    {
        if (!isset($this->di['auth']) || !$this->di['auth']->isClientLoggedIn()) {
            return null;
        }

        $client = $this->di['loggedin_client'] ?? null;

        return $client instanceof \Box\Mod\Client\Entity\Client ? $client->getId() : null;
    }
}
