<?php

declare(strict_types=1);

namespace Box\Mod\Servicesaas\Bridge;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Shared validation, private directory guard, and atomic storage for uploaded files.
 */
final class UploadedFileHelper
{
    public const int MAX_FILE_BYTES = 5_242_880;

    public static function defaultBaseDir(?string $override = null): string
    {
        if ($override !== null && trim($override) !== '') {
            return rtrim($override, '/\\');
        }
        if (defined('PATH_DATA') && (string) PATH_DATA !== '') {
            return rtrim((string) PATH_DATA, '/\\') . '/uploads/manual-transfer';
        }

        return rtrim(sys_get_temp_dir(), '/\\') . '/fossbilling_manual_proofs';
    }

    public static function ensurePrivateDirectory(string $baseDir): void
    {
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0750, true);
        }

        $htaccess = $baseDir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        }

        $index = $baseDir . '/index.html';
        if (!is_file($index)) {
            file_put_contents($index, '');
        }
    }

    /**
     * @param array<string, string> $allowedMimes
     * @return array{source_type: string, file: mixed, tmp_name: string, size: int, mime: string, ext: string, allow_local_copy: bool}
     */
    public static function validateUpload(mixed $uploadedFile, array $allowedMimes, string $label): array
    {
        if ($uploadedFile instanceof UploadedFile) {
            if (!$uploadedFile->isValid()) {
                throw new \InvalidArgumentException($label . ' gagal diunggah.');
            }
            $tmpName = $uploadedFile->getPathname();
            $size = (int) $uploadedFile->getSize();
            $sourceType = 'symfony';
            $allowLocalCopy = false;
        } elseif (is_array($uploadedFile)) {
            if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException($label . ' wajib diunggah.');
            }
            $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
            $size = (int) ($uploadedFile['size'] ?? ($tmpName !== '' && is_file($tmpName) ? filesize($tmpName) : 0));
            $sourceType = 'array';
            $allowLocalCopy = !empty($uploadedFile['_allow_local_copy']);
        } else {
            throw new \InvalidArgumentException($label . ' wajib diunggah.');
        }

        if ($tmpName === '' || !is_file($tmpName) || $size <= 0) {
            throw new \InvalidArgumentException($label . ' tidak ditemukan atau kosong.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException($label . ' melebihi batas ukuran 5 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($tmpName));
        if (!isset($allowedMimes[$mime])) {
            throw new \InvalidArgumentException(sprintf('Format file %s tidak didukung (%s).', $label, $mime ?: 'unknown'));
        }

        return [
            'source_type' => $sourceType,
            'file' => $uploadedFile,
            'tmp_name' => $tmpName,
            'size' => $size,
            'mime' => $mime,
            'ext' => $allowedMimes[$mime],
            'allow_local_copy' => $allowLocalCopy,
        ];
    }

    /** @param array{source_type: string, file: mixed, tmp_name: string, allow_local_copy: bool} $validated */
    public static function persistValidated(array $validated, string $baseDir, string $filename): void
    {
        if ($validated['source_type'] === 'symfony' && $validated['file'] instanceof UploadedFile) {
            $validated['file']->move($baseDir, $filename);

            return;
        }

        $dest = $baseDir . '/' . $filename;
        if (is_uploaded_file($validated['tmp_name'])) {
            if (!@move_uploaded_file($validated['tmp_name'], $dest)) {
                throw new \RuntimeException('Failed to store uploaded file.');
            }

            return;
        }

        if ($validated['allow_local_copy'] && @copy($validated['tmp_name'], $dest)) {
            return;
        }

        throw new \RuntimeException('Upload origin is invalid.');
    }

    public static function deleteSafeFile(string $baseDir, string $filename): void
    {
        $safeName = basename($filename);
        if ($safeName === '') {
            return;
        }
        $path = $baseDir . '/' . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string, mixed>|null */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
