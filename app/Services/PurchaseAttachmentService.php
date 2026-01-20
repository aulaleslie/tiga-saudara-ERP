<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use RuntimeException;
use ZipArchive;

class PurchaseAttachmentService
{
    private const MAX_BYTES = 1048576;
    private const MAX_IMAGE_ATTEMPTS = 24;
    private const START_QUALITY = 85;
    private const MIN_QUALITY = 1;
    private const QUALITY_STEP = 15;
    private const RESIZE_FACTOR = 0.85;
    private const MIN_DIMENSION = 64;
    private const TEMP_DIR = 'temp/purchase-attachments';

    public function prepare(UploadedFile $file): array
    {
        Storage::makeDirectory(self::TEMP_DIR);

        $originalName = $file->getClientOriginalName();

        if ($this->isImage($file)) {
            if ($file->getSize() !== false && $file->getSize() <= self::MAX_BYTES) {
                return $this->prepareRaw($file, $originalName);
            }

            try {
                return $this->prepareImage($file, $originalName);
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Unsupported image type; fall back to zipping.
            }
        }

        if ($file->getSize() !== false && $file->getSize() <= self::MAX_BYTES) {
            return $this->prepareRaw($file, $originalName);
        }

        return $this->prepareZip($file, $originalName);
    }

    public function cleanup(array $preparedAttachments): void
    {
        foreach ($preparedAttachments as $prepared) {
            if (!empty($prepared['path'])) {
                Storage::delete($prepared['path']);
            }
        }
    }

    public function attachPrepared(\Modules\Purchase\Entities\Purchase $purchase, array $prepared): void
    {
        $path = $prepared['path'] ?? null;
        if (!$path) {
            return;
        }

        $purchase->addMedia(Storage::path($path))
            ->usingFileName($prepared['file_name'] ?? basename($path))
            ->withCustomProperties([
                'original_name' => $prepared['original_name'] ?? null,
                'compressed' => (bool) ($prepared['compressed'] ?? false),
                'zipped' => (bool) ($prepared['zipped'] ?? false),
            ])
            ->toMediaCollection('attachments');

        Storage::delete($path);
    }

    private function prepareImage(UploadedFile $file, string $originalName): array
    {
        $image = Image::make($file->getRealPath());
        $extension = $this->normalizeImageExtension($file);
        $baseName = $this->sanitizeBaseName($originalName);
        $fileName = $this->buildFileName($baseName, $extension);

        $quality = self::START_QUALITY;
        $attempts = 0;

        while ($attempts < self::MAX_IMAGE_ATTEMPTS) {
            $encoded = (string) $image->encode($extension, $quality);

            if (strlen($encoded) <= self::MAX_BYTES) {
                $path = self::TEMP_DIR . '/' . $fileName;
                Storage::put($path, $encoded);

                return [
                    'path' => $path,
                    'file_name' => $fileName,
                    'original_name' => $originalName,
                    'compressed' => true,
                    'zipped' => false,
                ];
            }

            $image = Image::make($encoded);

            if ($quality > self::MIN_QUALITY) {
                $quality = max(self::MIN_QUALITY, $quality - self::QUALITY_STEP);
            } else {
                $width = (int) floor($image->width() * self::RESIZE_FACTOR);
                $height = (int) floor($image->height() * self::RESIZE_FACTOR);

                if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
                    break;
                }

                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });

                $quality = self::START_QUALITY;
            }

            $attempts++;
        }

        throw new RuntimeException("Lampiran gambar \"{$originalName}\" tidak dapat dikompresi di bawah 1MB.");
    }

    private function prepareZip(UploadedFile $file, string $originalName): array
    {
        $baseName = $this->sanitizeBaseName($originalName);
        $fileName = $this->buildFileName($baseName, 'zip');
        $path = self::TEMP_DIR . '/' . $fileName;
        $zipPath = Storage::path($path);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat arsip lampiran.');
        }

        $zip->addFile($file->getRealPath(), $originalName);
        if (method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName($originalName, ZipArchive::CM_DEFLATE, 9);
        }
        $zip->close();

        if (filesize($zipPath) > self::MAX_BYTES) {
            @unlink($zipPath);
            throw new RuntimeException("Lampiran \"{$originalName}\" tidak dapat diperkecil di bawah 1MB setelah dikompresi.");
        }

        return [
            'path' => $path,
            'file_name' => $fileName,
            'original_name' => $originalName,
            'compressed' => false,
            'zipped' => true,
        ];
    }

    private function prepareRaw(UploadedFile $file, string $originalName): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = $extension !== '' ? $extension : 'bin';
        $baseName = $this->sanitizeBaseName($originalName);
        $fileName = $this->buildFileName($baseName, $extension);
        $path = self::TEMP_DIR . '/' . $fileName;

        Storage::putFileAs(self::TEMP_DIR, $file, $fileName);

        return [
            'path' => $path,
            'file_name' => $fileName,
            'original_name' => $originalName,
            'compressed' => false,
            'zipped' => false,
        ];
    }

    private function isImage(UploadedFile $file): bool
    {
        $mime = $file->getMimeType();
        return $mime && Str::startsWith($mime, 'image/');
    }

    private function normalizeImageExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return in_array($extension, ['jpg', 'png', 'gif', 'webp'], true) ? $extension : 'jpg';
    }

    private function sanitizeBaseName(string $originalName): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $baseName = Str::slug($baseName);

        return $baseName !== '' ? $baseName : 'attachment';
    }

    private function buildFileName(string $baseName, string $extension): string
    {
        return $baseName . '-' . Str::uuid()->toString() . '.' . $extension;
    }
}
