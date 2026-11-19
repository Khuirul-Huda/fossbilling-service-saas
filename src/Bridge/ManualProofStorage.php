<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Doctrine\DBAL\Connection;

/**
 * Private disk storage and Option-A post-provisioning purge for manual payment proof files.
 */
final class ManualProofStorage
{
    /** @var array<string, string> */
    private const array ALLOWED_PROOF_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = UploadedFileHelper::defaultBaseDir($baseDir);
    }

    /** @return array{invoice_id: int, filename: string, mime: string, size: int, uploaded_at: string} */
    public function storeUploadedProof(int $invoiceId, mixed $uploadedFile): array
    {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Valid invoice ID is required to store payment proof.');
        }

        $validated = UploadedFileHelper::validateUpload($uploadedFile, self::ALLOWED_PROOF_MIMES, 'Bukti pembayaran');
        UploadedFileHelper::ensurePrivateDirectory($this->baseDir);
        $this->purgeProofForInvoice($invoiceId);

        $filename = sprintf('proof_%d_%s.%s', $invoiceId, bin2hex(random_bytes(8)), $validated['ext']);
        UploadedFileHelper::persistValidated($validated, $this->baseDir, $filename);

        $meta = [
            'invoice_id' => $invoiceId,
            'filename' => $filename,
            'mime' => $validated['mime'],
            'size' => $validated['size'],
            'uploaded_at' => gmdate('c'),
        ];
        file_put_contents($this->proofMetaPath($invoiceId), json_encode($meta, JSON_THROW_ON_ERROR));

        return $meta;
    }

    /** @return array{path: string, filename: string, mime: string, size: int, uploaded_at: string}|null */
    public function readProof(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $meta = UploadedFileHelper::readJson($this->proofMetaPath($invoiceId));
        if ($meta === null) {
            return null;
        }

        $filename = basename((string) ($meta['filename'] ?? ''));
        $path = $this->baseDir . '/' . $filename;
        if ($filename === '' || !is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'mime' => (string) ($meta['mime'] ?? 'application/octet-stream'),
            'size' => (int) ($meta['size'] ?? filesize($path)),
            'uploaded_at' => (string) ($meta['uploaded_at'] ?? ''),
        ];
    }

    public function purgeProofForInvoice(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        $purged = false;
        $metaPath = $this->proofMetaPath($invoiceId);
        $meta = UploadedFileHelper::readJson($metaPath);
        if ($meta !== null && !empty($meta['filename'])) {
            UploadedFileHelper::deleteSafeFile($this->baseDir, (string) $meta['filename']);
            $purged = true;
        }
        if (is_file($metaPath)) {
            @unlink($metaPath);
            $purged = true;
        }

        return $purged;
    }

    public function purgeProofsForOrder(Connection $dbal, int $orderId): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        $count = 0;
        foreach ($this->fetchOrderInvoiceIds($dbal, $orderId) as $invoiceId) {
            if ($this->purgeProofForInvoice($invoiceId)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<array{invoice_id: int, mime: string, size: int, uploaded_at: string}> */
    public function findExistingProofsForOrder(Connection $dbal, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $items = [];
        foreach ($this->fetchOrderInvoiceIds($dbal, $orderId) as $invoiceId) {
            $proof = $this->readProof($invoiceId);
            if ($proof !== null) {
                $items[] = [
                    'invoice_id' => $invoiceId,
                    'mime' => $proof['mime'],
                    'size' => $proof['size'],
                    'uploaded_at' => $proof['uploaded_at'],
                ];
            }
        }

        return $items;
    }

    /** @return list<int> */
    private function fetchOrderInvoiceIds(Connection $dbal, int $orderId): array
    {
        try {
            $rows = $dbal->fetchFirstColumn(
                'SELECT DISTINCT invoice_id FROM invoice_item WHERE rel_id = :order_id AND type = :type',
                ['order_id' => $orderId, 'type' => 'order'],
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (mixed $id): int => (int) $id, $rows);
    }

    private function proofMetaPath(int $invoiceId): string
    {
        return sprintf('%s/proof_%d.meta.json', $this->baseDir, $invoiceId);
    }
}
