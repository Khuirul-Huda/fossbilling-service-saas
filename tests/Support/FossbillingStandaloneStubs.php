<?php

declare(strict_types=1);

namespace FOSSBilling\Core\Container {
    if (!interface_exists(InjectionAwareInterface::class)) {
        interface InjectionAwareInterface
        {
            public function setDi(\Pimple\Container $di): void;

            public function getDi(): ?\Pimple\Container;
        }
    }
}

namespace FOSSBilling\Core\Exception {
    if (!class_exists(InformationException::class)) {
        class InformationException extends \RuntimeException
        {
            /** @param array<string, mixed>|null $variables */
            public function __construct(
                string $message = '',
                ?array $variables = null,
                int $code = 0,
                ?\Throwable $previous = null,
            ) {
                $formatted = $variables ? strtr($message, $variables) : $message;
                parent::__construct($formatted, $code, $previous);
            }
        }
    }
}

namespace Pimple {
    if (!class_exists(Container::class)) {
        class Container extends \ArrayObject
        {
        }
    }
}
