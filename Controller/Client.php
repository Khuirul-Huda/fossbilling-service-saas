<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Controller;

require_once dirname(__DIR__) . '/src/Compat.php';

use Box\Mod\Servicesaas\Bridge\ManualPaymentHandler;
use Box\Mod\Servicesaas\Bridge\PortalSso;
use Box\Mod\Servicesaas\Compat\InjectionAwareInterface;
use Box\Mod\Servicesaas\Compat\RuntimeCompat;
use Box\Mod\Servicesaas\Crypto\EncryptedChannel;
use Box\Mod\Servicesaas\Service;
use Symfony\Component\HttpFoundation\Response;

class Client implements InjectionAwareInterface
{
    private const int HTTP_NOT_FOUND = 404;
    private const int HTTP_FORBIDDEN = 403;

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
        $app->get('/servicesaas/return/:order_id', 'get_return', ['order_id' => '[0-9]+'], static::class);
        $app->get('/servicesaas/portal', 'get_portal', [], static::class);
        $app->post('/servicesaas/manual-confirm', 'post_manual_confirm', [], static::class);
    }

    public function get_return(mixed $app, int|string $order_id): Response
    {
        $orderId = (int) $order_id;
        $service = $this->di['mod_service']('servicesaas');
        if (!$service instanceof Service) {
            return RuntimeCompat::htmlResponse('', self::HTTP_NOT_FOUND);
        }

        $clientId = $this->resolveLoggedInClientId();
        $request = $this->resolveRequest($app);
        $status = (string) $request->query->get('status', 'success');

        try {
            $redirectUrl = $service->resolveValidatedReturnUrl($orderId, $clientId, $status);
        } catch (\Throwable) {
            return RuntimeCompat::htmlResponse('', self::HTTP_NOT_FOUND);
        }

        if ($redirectUrl === null) {
            return $app->redirect('/order/service/manage/' . $orderId);
        }

        return $app->redirectUrl($redirectUrl, Response::HTTP_FOUND);
    }

    public function get_portal(mixed $app): Response
    {
        $service = $this->di['mod_service']('servicesaas');
        if (!$service instanceof Service) {
            return RuntimeCompat::htmlResponse('', self::HTTP_NOT_FOUND);
        }

        $token = (string) $this->resolveRequest($app)->query->get('token', '');
        try {
            $sso = new PortalSso(EncryptedChannel::persistent(), $service->getAppRegistry(), $service->getRepository());
            $target = $sso->resolvePortalTarget($token);
        } catch (\Throwable) {
            return RuntimeCompat::htmlResponse('Invalid or expired portal login link.', self::HTTP_FORBIDDEN);
        }

        if (isset($this->di['session'])) {
            $this->di['session']->set('client_id', $target['client_id']);
        }

        return $app->redirect($target['redirect_path']);
    }

    public function post_manual_confirm(mixed $app): Response
    {
        $service = $this->di['mod_service']('servicesaas');
        if (!$service instanceof Service) {
            return RuntimeCompat::htmlResponse('', self::HTTP_NOT_FOUND);
        }

        $post = $this->resolveRequest($app)->request->all();
        try {
            $handler = new ManualPaymentHandler($this->di, $service);
            $result = $handler->handleSubmission($post);
        } catch (\Throwable $e) {
            return RuntimeCompat::htmlResponse('Manual payment confirmation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 400);
        }

        $redirectUrl = $result['redirect_url'];
        if (str_starts_with($redirectUrl, 'http://') || str_starts_with($redirectUrl, 'https://')) {
            return $app->redirectUrl($redirectUrl, Response::HTTP_FOUND);
        }

        return $app->redirect($redirectUrl);
    }

    private function resolveRequest(mixed $app): \Symfony\Component\HttpFoundation\Request
    {
        if (is_object($app) && method_exists($app, 'getRequest')) {
            return $app->getRequest();
        }

        return $this->di['request'];
    }

    private function resolveLoggedInClientId(): ?int
    {
        if (!isset($this->di['auth']) || !$this->di['auth']->isClientLoggedIn()) {
            return null;
        }

        return RuntimeCompat::clientId($this->di['loggedin_client'] ?? null);
    }
}
