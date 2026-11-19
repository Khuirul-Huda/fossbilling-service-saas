<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Servicesaas\Compat\RuntimeCompat;

/**
 * Bridges invoice and gateway operations across FOSSBilling 0.8.9 (RedBean) and main (Doctrine).
 */
final class InvoiceCompat
{
    public static function findById(\Pimple\Container $di, int $invoiceId): ?object
    {
        if (RuntimeCompat::usesDoctrineInvoice($di)) {
            return $di['em']->getRepository(Invoice::class)->find($invoiceId);
        }

        $model = $di['db']->load('Invoice', $invoiceId);

        return is_object($model) && !empty($model->id) ? $model : null;
    }

    public static function findByHash(\Pimple\Container $di, string $hash): ?object
    {
        if (RuntimeCompat::usesDoctrineInvoice($di)) {
            return $di['em']->getRepository(Invoice::class)->findOneBy(['hash' => $hash]);
        }

        $model = $di['db']->findOne('Invoice', 'hash = ?', [$hash]);

        return is_object($model) ? $model : null;
    }

    public static function isUnpaid(object $invoice): bool
    {
        return self::status($invoice) === 'unpaid';
    }

    public static function status(object $invoice): string
    {
        $raw = method_exists($invoice, 'getStatus') ? $invoice->getStatus() : ($invoice->status ?? 'unknown');

        return strtolower((string) $raw);
    }

    public static function id(object $invoice): int
    {
        return method_exists($invoice, 'getId') ? (int) $invoice->getId() : (int) ($invoice->id ?? 0);
    }

    public static function hash(object $invoice): string
    {
        return (string) (method_exists($invoice, 'getHash') ? $invoice->getHash() : ($invoice->hash ?? ''));
    }

    public static function baseIncome(object $invoice): float
    {
        return (float) (method_exists($invoice, 'getBaseIncome') ? $invoice->getBaseIncome() : ($invoice->base_income ?? 0));
    }

    public static function generateForOrder(\Pimple\Container $di, Order $order): object
    {
        $invoiceService = $di['mod_service']('invoice');
        if (RuntimeCompat::usesDoctrineInvoice($di)) {
            return $invoiceService->generateForOrder($order);
        }

        $rbOrder = $di['db']->getExistingModelById('ClientOrder', (int) $order->getId(), 'Order not found.');

        return $invoiceService->generateForOrder($rbOrder);
    }

    public static function issueIfNeeded(\Pimple\Container $di, object $invoice): void
    {
        $invoiceService = $di['mod_service']('invoice');
        if (!method_exists($invoiceService, 'issueInvoice')) {
            return;
        }
        if (method_exists($invoice, 'isIssued') && !$invoice->isIssued()) {
            $invoiceService->issueInvoice($invoice, ['id' => self::id($invoice), 'use_credits' => true]);
        }
    }

    /** @param array{order: Order, pending: array<string, mixed>} $ctx */
    public static function applyPendingUpgrade(\Pimple\Container $di, object $invoice, array $ctx): void
    {
        $order = $ctx['order'];
        $pending = $ctx['pending'];
        $targetPrice = (float) ($pending['price'] ?? $order->getPrice());
        $targetTitle = (string) ($pending['title'] ?? $order->getTitle());
        $targetPeriod = is_string($pending['period'] ?? null) ? $pending['period'] : $order->getPeriod();

        if (RuntimeCompat::usesDoctrineInvoice($di) && $invoice instanceof Invoice) {
            $invoice->setBaseIncome((string) $targetPrice);
            if (class_exists(InvoiceItem::class)) {
                try {
                    foreach ($di['em']->getRepository(InvoiceItem::class)->findBy(['invoice' => $invoice]) as $item) {
                        if ($item instanceof InvoiceItem) {
                            $item->setTitle($targetTitle);
                            $item->setPrice($targetPrice);
                            $item->setPeriod($targetPeriod);
                            $di['em']->persist($item);
                        }
                    }
                } catch (\Throwable) {
                }
            }
            $di['em']->persist($invoice);
            $di['em']->flush();

            return;
        }

        $invoice->base_income = (string) $targetPrice;
        $di['db']->store($invoice);
        foreach ($di['db']->find('InvoiceItem', 'invoice_id = ?', [self::id($invoice)]) as $item) {
            $item->title = $targetTitle;
            $item->price = $targetPrice;
            $item->period = $targetPeriod;
            $di['db']->store($item);
        }
    }

    public static function cancelUnpaid(\Pimple\Container $di, object $invoice, string $reason): void
    {
        $invoiceService = $di['mod_service']('invoice');
        if (RuntimeCompat::usesDoctrineInvoice($di) && $invoice instanceof Invoice) {
            if (method_exists($invoiceService, 'cancelInvoice') && $invoice->isIssued()) {
                $invoiceService->cancelInvoice($invoice, ['reason' => $reason]);
            } else {
                $invoice->setStatus(Invoice::STATUS_CANCELED);
                $di['em']->persist($invoice);
            }

            return;
        }

        $invoice->status = 'canceled';
        $di['db']->store($invoice);
    }

    public static function isEnabledManualGateway(\Pimple\Container $di, int $gatewayId): bool
    {
        if ($gatewayId <= 0) {
            return false;
        }
        if (RuntimeCompat::usesDoctrineInvoice($di) && class_exists(PayGateway::class)) {
            $gateway = $di['em']->getRepository(PayGateway::class)->find($gatewayId);

            return $gateway instanceof PayGateway
                && $gateway->isEnabled()
                && RuntimeCompat::isManualApprovalGateway((string) $gateway->getGateway());
        }

        $gateway = $di['db']->load('PayGateway', $gatewayId);

        return is_object($gateway)
            && !empty($gateway->enabled)
            && RuntimeCompat::isManualApprovalGateway((string) ($gateway->gateway ?? ''));
    }
}
