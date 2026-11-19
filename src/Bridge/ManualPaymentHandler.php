<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Box\Mod\Servicesaas\Compat\RuntimeCompat;
use Box\Mod\Servicesaas\Service;

/**
 * Handles manual QRIS / Bank Transfer payment confirmations and invoice return status lookup.
 */
final class ManualPaymentHandler
{
    public function __construct(
        private readonly \Pimple\Container $di,
        private readonly Service $saasService,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{invoice_hash: string, redirect_url: string}
     */
    public function handleSubmission(array $post): array
    {
        $invoiceHash = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($post['invoice_hash'] ?? ''));
        $gatewayId = is_numeric($post['gateway_id'] ?? null) ? (int) $post['gateway_id'] : 0;
        $senderName = trim((string) ($post['sender_name'] ?? ''));

        if ($invoiceHash === '' || $gatewayId <= 0 || $senderName === '') {
            throw new \InvalidArgumentException('Invoice hash, gateway ID, and sender name are required.');
        }

        $invoice = InvoiceCompat::findByHash($this->di, $invoiceHash);
        if ($invoice === null || !InvoiceCompat::isUnpaid($invoice)) {
            throw new \RuntimeException('Unpaid invoice was not found.');
        }

        if (!InvoiceCompat::isEnabledManualGateway($this->di, $gatewayId)) {
            throw new \RuntimeException('Selected payment gateway does not support manual confirmation.');
        }

        $invoiceId = InvoiceCompat::id($invoice);
        $txnId = $this->resolveTxnId($post, $invoiceId);
        $note = $this->formatNote($post);

        $this->di['mod_service']('invoice', 'transaction')->create([
            'invoice_id' => $invoiceId,
            'gateway_id' => $gatewayId,
            'txn_id' => $txnId,
            'note' => $note,
            'source' => 'manual_form',
            'post' => $post,
        ]);

        $pendingReturnUrl = $this->saasService->resolveReturnRedirectForInvoiceId($invoiceId, 'pending');
        $fallbackUrl = $this->buildInvoiceUrl($invoiceHash);

        return [
            'invoice_hash' => $invoiceHash,
            'redirect_url' => $pendingReturnUrl ?? $fallbackUrl,
        ];
    }

    /**
     * @return array{is_saas: bool, status: string, return_url: ?string, pending_return_url: ?string, has_pending_manual_tx: bool}
     */
    public function getInvoiceReturnContext(string $hash): array
    {
        $cleanHash = preg_replace('/[^a-zA-Z0-9]/', '', trim($hash));
        if ($cleanHash === '') {
            return $this->emptyReturnContext();
        }

        $invoice = InvoiceCompat::findByHash($this->di, $cleanHash);
        if ($invoice === null) {
            return $this->emptyReturnContext();
        }

        $invoiceId = InvoiceCompat::id($invoice);
        $returnUrl = $this->saasService->resolveReturnRedirectForInvoiceId($invoiceId, 'success');
        $pendingUrl = $this->saasService->resolveReturnRedirectForInvoiceId($invoiceId, 'pending');

        return [
            'is_saas' => $returnUrl !== null || $pendingUrl !== null,
            'status' => InvoiceCompat::status($invoice),
            'return_url' => $returnUrl,
            'pending_return_url' => $pendingUrl,
            'has_pending_manual_tx' => $this->hasPendingManualTransaction($invoiceId),
        ];
    }

    /** @return array{is_saas: bool, status: string, return_url: ?string, pending_return_url: ?string, has_pending_manual_tx: bool} */
    private function emptyReturnContext(): array
    {
        return [
            'is_saas' => false,
            'status' => 'unknown',
            'return_url' => null,
            'pending_return_url' => null,
            'has_pending_manual_tx' => false,
        ];
    }

    private function hasPendingManualTransaction(int $invoiceId): bool
    {
        $conn = RuntimeCompat::dbal($this->di);
        if (!$conn->createSchemaManager()->tablesExist(['transaction', 'pay_gateway'])) {
            return false;
        }

        $rows = $conn->fetchAllAssociative(
            "SELECT pg.gateway FROM `transaction` t INNER JOIN pay_gateway pg ON pg.id = t.gateway_id WHERE t.invoice_id = :invoice_id AND t.status = 'received'",
            ['invoice_id' => $invoiceId],
        );

        foreach ($rows as $row) {
            $gwCode = (string) ($row['gateway'] ?? '');
            if ($gwCode !== '' && RuntimeCompat::isManualApprovalGateway($gwCode)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $post */
    private function resolveTxnId(array $post, int $invoiceId): string
    {
        $raw = trim((string) ($post['txn_id'] ?? ''));

        return $raw !== '' ? $raw : sprintf('MANUAL-INV%d-%s', $invoiceId, strtoupper(bin2hex(random_bytes(4))));
    }

    /** @param array<string, mixed> $post */
    private function formatNote(array $post): string
    {
        $parts = [];
        $channel = trim((string) ($post['payment_channel'] ?? ''));
        $sender = trim((string) ($post['sender_name'] ?? ''));
        $bank = trim((string) ($post['sender_bank'] ?? ''));
        $ref = trim((string) ($post['transfer_ref'] ?? ''));

        if ($channel !== '') {
            $parts[] = $channel;
        }
        if ($sender !== '') {
            $parts[] = 'Pengirim: ' . $sender;
        }
        if ($bank !== '') {
            $parts[] = 'Bank/Wallet: ' . $bank;
        }
        if ($ref !== '') {
            $parts[] = 'Ref: ' . $ref;
        }

        return implode(' | ', $parts);
    }

    private function buildInvoiceUrl(string $hash): string
    {
        if (isset($this->di['url'])) {
            return (string) $this->di['url']->link('invoice/' . $hash);
        }

        return '/invoice/' . $hash;
    }
}
