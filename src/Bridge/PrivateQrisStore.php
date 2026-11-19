<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

/**
 * Private disk storage and HMAC-signed URL verification for uploaded fallback QRIS images.
 */
final class PrivateQrisStore
{
    /** @var array<string, string> */
    private const array ALLOWED_QRIS_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = UploadedFileHelper::defaultBaseDir($baseDir);
    }

    /** @return array{filename: string, mime: string, size: int, uploaded_at: string} */
    public function store(mixed $uploadedFile): array
    {
        $validated = UploadedFileHelper::validateUpload($uploadedFile, self::ALLOWED_QRIS_MIMES, 'Gambar QRIS fallback');
        UploadedFileHelper::ensurePrivateDirectory($this->baseDir);
        $this->delete();

        $filename = 'qris-fallback.' . $validated['ext'];
        UploadedFileHelper::persistValidated($validated, $this->baseDir, $filename);

        $meta = [
            'filename' => $filename,
            'mime' => $validated['mime'],
            'size' => $validated['size'],
            'uploaded_at' => gmdate('c'),
        ];
        file_put_contents($this->metaPath(), json_encode($meta, JSON_THROW_ON_ERROR));

        return $meta;
    }

    /** @return array{path: string, filename: string, mime: string, size: int, uploaded_at: string}|null */
    public function read(): ?array
    {
        $meta = UploadedFileHelper::readJson($this->metaPath());
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
            'mime' => (string) ($meta['mime'] ?? 'image/png'),
            'size' => (int) ($meta['size'] ?? filesize($path)),
            'uploaded_at' => (string) ($meta['uploaded_at'] ?? ''),
        ];
    }

    public function delete(): void
    {
        $metaPath = $this->metaPath();
        $meta = UploadedFileHelper::readJson($metaPath);
        if ($meta !== null && !empty($meta['filename'])) {
            UploadedFileHelper::deleteSafeFile($this->baseDir, (string) $meta['filename']);
        }
        foreach (['jpg', 'png', 'webp'] as $ext) {
            UploadedFileHelper::deleteSafeFile($this->baseDir, 'qris-fallback.' . $ext);
        }
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }
    }

    /** @return array{invoice: string, exp: int, sig: string} */
    public function signParams(string $invoiceHash, int $ttlSeconds = 3600): array
    {
        $cleanHash = preg_replace('/[^a-zA-Z0-9]/', '', $invoiceHash);
        if ($cleanHash === '') {
            throw new \InvalidArgumentException('Invoice hash is required to sign QRIS URL.');
        }

        UploadedFileHelper::ensurePrivateDirectory($this->baseDir);
        $exp = time() + max(60, $ttlSeconds);
        $sig = hash_hmac('sha256', $cleanHash . '|' . $exp, $this->signingSecret());

        return ['invoice' => $cleanHash, 'exp' => $exp, 'sig' => $sig];
    }

    public function verifySignature(string $invoiceHash, int $exp, string $sig): bool
    {
        $cleanHash = preg_replace('/[^a-zA-Z0-9]/', '', $invoiceHash);
        if ($cleanHash === '' || $exp < time() || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            return false;
        }

        $expected = hash_hmac('sha256', $cleanHash . '|' . $exp, $this->signingSecret());

        return hash_equals($expected, $sig);
    }

    private function signingSecret(): string
    {
        $keyPath = $this->baseDir . '/.signing_key';
        if (is_file($keyPath)) {
            $existing = trim((string) file_get_contents($keyPath));
            if ($existing !== '') {
                return $existing;
            }
        }

        $secret = bin2hex(random_bytes(32));
        file_put_contents($keyPath, $secret);
        @chmod($keyPath, 0600);

        return $secret;
    }

    private function metaPath(): string
    {
        return $this->baseDir . '/qris-fallback.meta.json';
    }
}
