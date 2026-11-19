<?php

declare(strict_types=1);

namespace {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'Box\\Mod\\Servicesaas\\')) {
            return;
        }
        $relative = substr($class, strlen('Box\\Mod\\Servicesaas\\'));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

namespace Box\Mod\Servicesaas\Compat {
    use Box\Mod\Invoice\Entity\Invoice;
    use Box\Mod\Invoice\ServicePayGateway;
    use Doctrine\DBAL\Connection;
    use Symfony\Component\HttpFoundation\RedirectResponse;
    use Symfony\Component\HttpFoundation\Response;

    if (!interface_exists(InjectionAwareInterface::class, false)) {
        if (interface_exists(\FOSSBilling\Core\Container\InjectionAwareInterface::class)) {
            class_alias(\FOSSBilling\Core\Container\InjectionAwareInterface::class, InjectionAwareInterface::class);
        } elseif (interface_exists(\FOSSBilling\InjectionAwareInterface::class)) {
            class_alias(\FOSSBilling\InjectionAwareInterface::class, InjectionAwareInterface::class);
        } else {
            interface InjectionAwareInterface {}
        }
    }

    if (!interface_exists(WidgetProviderInterface::class, false)) {
        if (interface_exists(\Box\Mod\Widgets\ProviderInterface::class)) {
            class_alias(\Box\Mod\Widgets\ProviderInterface::class, WidgetProviderInterface::class);
        } elseif (interface_exists(\FOSSBilling\Interfaces\WidgetProviderInterface::class)) {
            class_alias(\FOSSBilling\Interfaces\WidgetProviderInterface::class, WidgetProviderInterface::class);
        } else {
            interface WidgetProviderInterface {}
        }
    }

    if (!class_exists(AbstractApi::class, false)) {
        if (class_exists(\FOSSBilling\Core\Api\AbstractApi::class)) {
            class_alias(\FOSSBilling\Core\Api\AbstractApi::class, AbstractApi::class);
        } elseif (class_exists(\FOSSBilling\Api\AbstractApi::class)) {
            class_alias(\FOSSBilling\Api\AbstractApi::class, AbstractApi::class);
        } else {
            abstract class AbstractApi {}
        }
    }

    if (!class_exists(InformationException::class, false)) {
        if (class_exists(\FOSSBilling\Core\Exception\InformationException::class)) {
            class_alias(\FOSSBilling\Core\Exception\InformationException::class, InformationException::class);
        } elseif (class_exists(\FOSSBilling\InformationException::class)) {
            class_alias(\FOSSBilling\InformationException::class, InformationException::class);
        } else {
            class InformationException extends \RuntimeException {}
        }
    }

    final class RuntimeCompat
    {
        public static function dbal(\Pimple\Container $di): Connection
        {
            if (isset($di['dbal']) && $di['dbal'] instanceof Connection) {
                return $di['dbal'];
            }

            return $di['em']->getConnection();
        }

        public static function usesDoctrineInvoice(\Pimple\Container $di): bool
        {
            return class_exists(Invoice::class) && isset($di['em']);
        }

        public static function clientId(?object $client): ?int
        {
            if ($client === null) {
                return null;
            }
            if (method_exists($client, 'getId')) {
                $id = $client->getId();

                return is_numeric($id) ? (int) $id : null;
            }
            if (isset($client->id) && is_numeric($client->id)) {
                return (int) $client->id;
            }

            return null;
        }

        public static function isManualApprovalGateway(string $gatewayCode): bool
        {
            if ($gatewayCode === '') {
                return false;
            }
            if (class_exists(ServicePayGateway::class) && method_exists(ServicePayGateway::class, 'isManualApprovalGateway')) {
                return ServicePayGateway::isManualApprovalGateway($gatewayCode);
            }

            $class = 'Payment_Adapter_' . $gatewayCode;
            if (!class_exists($class) && defined('PATH_LIBRARY')) {
                $nested = PATH_LIBRARY . '/Payment/Adapter/' . $gatewayCode . '/' . $gatewayCode . '.php';
                $flat = PATH_LIBRARY . '/Payment/Adapter/' . $gatewayCode . '.php';
                $file = is_file($nested) ? $nested : (is_file($flat) ? $flat : null);
                if ($file !== null) {
                    require_once $file;
                }
            }

            return class_exists($class)
                && is_callable([$class, 'requiresManualApproval'])
                && (bool) $class::requiresManualApproval();
        }

        public static function htmlResponse(string $html, int $status): Response
        {
            if (class_exists(\FOSSBilling\Core\Http\ResponseFactory::class)) {
                return (new \FOSSBilling\Core\Http\ResponseFactory())->html($html, $status);
            }

            return new Response($html, $status);
        }

        public static function binaryResponse(string $path, string $mime, string $filename = ''): Response
        {
            $headers = [
                'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Robots-Tag' => 'noindex, noarchive',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($filename !== '') {
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename));
                $headers['Content-Disposition'] = 'inline; filename="' . $safeName . '"';
            }

            return new Response((string) file_get_contents($path), Response::HTTP_OK, $headers);
        }

        public static function redirectResponse(mixed $app, string $url): Response
        {
            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                if (is_object($app) && method_exists($app, 'redirectUrl')) {
                    return $app->redirectUrl($url, Response::HTTP_FOUND);
                }

                return new RedirectResponse($url, Response::HTTP_FOUND);
            }
            if (is_object($app) && method_exists($app, 'redirect')) {
                return $app->redirect($url);
            }

            return new RedirectResponse($url, Response::HTTP_FOUND);
        }
    }
}
