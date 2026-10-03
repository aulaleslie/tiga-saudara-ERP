<?php

namespace Modules\Purchase\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class IndividualPurchasePaymentAttachmentStagingService
{
    public const STAGING_DIR = 'temp/dropzone';

    public const TARGET_BYTES = 1048576; // 1 MB (1024 * 1024)

    /**
     * Allowed MIME types and their permitted file extensions.
     */
    protected const ALLOWED_MIME_TO_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'text/plain' => ['txt'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/bmp' => ['bmp'],
    ];

    /**
     * Extension to MIME lookup for secondary cross-validation.
     */
    protected const ALLOWED_EXTENSION_TO_MIMES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
    ];

    /**
     * Formats supported by Intervention Image (with GD driver).
     */
    protected const COMPRESSIBLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Validate and stage an uploaded file into temp/dropzone.
     *
     * @param UploadedFile $file
     * @return array{name: string, original_name: string}
     * @throws \InvalidArgumentException
     */
    public function stageUploadedFile(UploadedFile $file): array
    {
        $this->assertValidFile($file);

        $clientExtension = strtolower($file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName();
        $storedName = Str::uuid()->toString() . '.' . $clientExtension;

        // Perform single-pass compression for compressible images
        $processedBytes = null;
        if ($this->isCompressibleImage($clientExtension, $file->getRealPath())) {
            $processedBytes = $this->attemptSinglePassCompression($file, $clientExtension);
        }

        Storage::makeDirectory(self::STAGING_DIR);

        if ($processedBytes !== null) {
            Storage::put(self::STAGING_DIR . '/' . $storedName, $processedBytes);
        } else {
            Storage::putFileAs(self::STAGING_DIR, $file, $storedName);
        }

        // Store original name metadata alongside staged file
        $metaPath = self::STAGING_DIR . '/' . $storedName . '.meta';
        Storage::put($metaPath, json_encode([
            'original_name' => $originalName,
        ], JSON_UNESCAPED_UNICODE));

        return [
            'name' => $storedName,
            'original_name' => $originalName,
        ];
    }

    /**
     * Assert that the uploaded file matches allowed extensions and real MIME content.
     * Rejects disguised or active content.
     *
     * @param UploadedFile $file
     * @throws \InvalidArgumentException
     */
    public function assertValidFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Unggah berkas gagal atau berkas tidak valid.');
        }

        $realPath = $file->getRealPath();
        if (!$realPath || !file_exists($realPath) || !is_readable($realPath)) {
            throw new \InvalidArgumentException('Berkas lampiran tidak dapat dibaca.');
        }

        $clientExtension = strtolower($file->getClientOriginalExtension());
        if (!array_key_exists($clientExtension, self::ALLOWED_EXTENSION_TO_MIMES)) {
            throw new \InvalidArgumentException("Ekstensi berkas '{$clientExtension}' tidak didukung.");
        }

        $detectedMime = $this->detectMimeType($realPath);
        if (empty($detectedMime)) {
            throw new \InvalidArgumentException('Gagal mendeteksi tipe konten berkas lampiran.');
        }

        $detectedMime = strtolower($detectedMime);
        $allowedMimesForExt = self::ALLOWED_EXTENSION_TO_MIMES[$clientExtension];

        if (!in_array($detectedMime, $allowedMimesForExt, true)) {
            // Check if detected MIME maps to extension in the inverted map
            $validExtsForMime = self::ALLOWED_MIME_TO_EXTENSIONS[$detectedMime] ?? [];
            if (!in_array($clientExtension, $validExtsForMime, true)) {
                throw new \InvalidArgumentException(
                    "Tipe konten berkas ({$detectedMime}) tidak cocok dengan format berkas yang diizinkan."
                );
            }
        }

        // Active script / executable prevention
        $this->assertNoExecutableContent($realPath, $clientExtension, $detectedMime);

        // Office OpenXML package integrity check for DOCX and XLSX
        if (in_array($clientExtension, ['docx', 'xlsx'], true)) {
            $this->validateOpenXmlPackage($realPath, $clientExtension);
        }
    }

    /**
     * Validate that a DOCX or XLSX file contains valid Office OpenXML package entries.
     * Prevents arbitrary/generic ZIP files renamed to .docx or .xlsx.
     *
     * @param string $path
     * @param string $extension
     * @throws \InvalidArgumentException
     */
    protected function validateOpenXmlPackage(string $path, string $extension): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \InvalidArgumentException('Ekstensi PHP ZipArchive diperlukan untuk memverifikasi dokumen Office OpenXML.');
        }

        $zip = new \ZipArchive();
        $res = $zip->open($path, \ZipArchive::RDONLY);
        if ($res !== true) {
            throw new \InvalidArgumentException('Berkas OpenXML tidak valid atau arsip rusak.');
        }

        // Both DOCX and XLSX must contain [Content_Types].xml
        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;

        // Specific part requirement
        $hasOfficePart = false;
        if ($extension === 'docx') {
            $hasOfficePart = ($zip->locateName('word/document.xml') !== false)
                || ($zip->locateName('word/_rels/document.xml.rels') !== false);
        } elseif ($extension === 'xlsx') {
            $hasOfficePart = ($zip->locateName('xl/workbook.xml') !== false)
                || ($zip->locateName('xl/_rels/workbook.xml.rels') !== false);
        }

        $zip->close();

        if (!$hasContentTypes || !$hasOfficePart) {
            $formatName = strtoupper($extension);
            throw new \InvalidArgumentException("Berkas bukan dokumen {$formatName} yang valid.");
        }
    }

    /**
     * Attempt one image compression pass without cropping or altering aspect ratio.
     * Keeps smaller valid result or returns null to use original bytes.
     *
     * @param UploadedFile $file
     * @param string $extension
     * @return string|null Encoded image binary string if smaller, or null if original is preferred/unsupported.
     */
    public function attemptSinglePassCompression(UploadedFile $file, string $extension): ?string
    {
        $originalSize = $file->getSize();
        if ($originalSize === false) {
            $originalSize = @filesize($file->getRealPath()) ?: 0;
        }

        try {
            $image = Image::make($file->getRealPath());

            // Target quality: 80% compression pass without cropping or changing dimensions
            $normalizedFormat = in_array($extension, ['jpg', 'jpeg'], true) ? 'jpg' : $extension;
            $encoded = (string) $image->encode($normalizedFormat, 80);

            // Verify encoded result is valid and non-empty
            if (!empty($encoded) && strlen($encoded) < $originalSize) {
                return $encoded;
            }
        } catch (\Throwable $e) {
            // Unsupported compressor format or processing failure: gracefully fall back to original
            return null;
        }

        return null;
    }

    /**
     * Check if the file is a compressible image extension and readable by image processing.
     */
    protected function isCompressibleImage(string $extension, string $realPath): bool
    {
        return in_array($extension, self::COMPRESSIBLE_EXTENSIONS, true);
    }

    /**
     * Detect real MIME type via finfo / mime_content_type.
     */
    public function detectMimeType(string $realPath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $realPath);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($realPath);
            if ($mime) {
                return $mime;
            }
        }

        return '';
    }

    /**
     * Block executable / script payloads even if disguised.
     */
    protected function assertNoExecutableContent(string $realPath, string $extension, string $mime): void
    {
        $disallowedMimes = [
            'application/x-php',
            'application/x-httpd-php',
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-dosexec',
            'application/javascript',
            'text/javascript',
            'application/x-sh',
            'text/x-shellscript',
        ];

        if (in_array($mime, $disallowedMimes, true)) {
            throw new \InvalidArgumentException('Berkas yang berisi skrip atau program yang dapat dieksekusi ditolak.');
        }

        // For plain text, ensure it doesn't start with PHP open tag or script
        if ($extension === 'txt' || $mime === 'text/plain') {
            $handle = @fopen($realPath, 'rb');
            if ($handle) {
                $head = fread($handle, 256);
                fclose($handle);
                if (is_string($head) && (str_contains($head, '<?php') || str_contains($head, '<script'))) {
                    throw new \InvalidArgumentException('Berkas teks terdeteksi berisi kode skrip.');
                }
            }
        }
    }
}
