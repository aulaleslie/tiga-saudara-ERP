<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Responsible for replicating an attachment to multiple SalePayment records.
 * Handles media copy, tracking, and cleanup on failure.
 */
class GlobalSalePaymentAttachmentReplicator
{
    /**
     * Collection of media paths that were successfully created.
     * Used for cleanup if replication fails partway through.
     *
     * @var array
     */
    protected array $copiedMediaPaths = [];

    /**
     * Optional test seam to inject failure at a specific payment index.
     * If set, throws an exception before copying the specified payment.
     *
     * @var int|null
     */
    public ?int $failBeforePaymentIndex = null;

    /**
     * Validate the source attachment file before any payment creation begins.
     *
     * @param string|null $sourcePath
     * @throws ValidationException
     */
    public function validateSourcePath(?string $sourcePath): void
    {
        if (empty($sourcePath)) {
            return;
        }

        if (!file_exists($sourcePath)) {
            throw ValidationException::withMessages([
                'attachment' => 'File lampiran tidak ditemukan atau tidak valid.',
            ]);
        }
    }

    /**
     * Replicate the attachment from source to each payment atomically.
     * Tracks all copied media paths for cleanup on failure.
     *
     * Must be called inside a database transaction so that the entire operation
     * rolls back if any payment copy fails.
     *
     * @param string|null $sourcePath Path to temporary attachment file
     * @param array $payments Array of SalePayment instances to receive the attachment
     * @throws \Exception
     */
    public function replicateToPayments(?string $sourcePath, array $payments): void
    {
        if (empty($sourcePath) || empty($payments)) {
            return;
        }

        if (!file_exists($sourcePath)) {
            throw ValidationException::withMessages([
                'attachment' => 'File lampiran tidak ditemukan atau tidak dapat diakses.',
            ]);
        }

        foreach ($payments as $index => $payment) {
            // Allow test injection of failure at a specific payment index
            if ($this->failBeforePaymentIndex !== null && $index === $this->failBeforePaymentIndex) {
                throw new \Exception('Injected replicator failure before payment ' . ($index + 1));
            }

            try {
                $payment->addMedia($sourcePath)
                    ->preservingOriginal()
                    ->toMediaCollection('attachments');

                // Track the path for cleanup if later copies fail
                foreach ($payment->getMedia('attachments') as $media) {
                    $this->copiedMediaPaths[] = $media->getPath();
                }
            } catch (\Exception $e) {
                // Re-throw to trigger transaction rollback
                throw $e;
            }
        }
    }

    /**
     * Clean up any media files that were created before a failure.
     * Called in the exception handler after transaction rollback.
     *
     * Handles complete Media Library artifacts: directories, conversions, responsive images, etc.
     * Does not throw; logs failures silently to avoid masking the original exception.
     */
    public function cleanupOnFailure(): void
    {
        foreach ($this->copiedMediaPaths as $mediaPath) {
            try {
                // Remove primary file if it exists
                if (file_exists($mediaPath)) {
                    @unlink($mediaPath);
                }

                // Remove the entire media directory (contains conversions, responsive images, etc.)
                $mediaDirectory = dirname($mediaPath);
                if (is_dir($mediaDirectory)) {
                    $this->removeDirectory($mediaDirectory);
                }
            } catch (\Throwable $e) {
                // Silently log or ignore cleanup failures to preserve original exception
            }
        }

        // Clear the tracked paths after cleanup to prevent double-cleanup or accidental re-deletion
        $this->copiedMediaPaths = [];
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * @param string $directory
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $files = @scandir($directory);
            if ($files === false) {
                return;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $file;

                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    @unlink($path);
                }
            }

            @rmdir($directory);
        } catch (\Throwable $e) {
            // Silently ignore directory removal failures
        }
    }

    /**
     * Reset the per-operation runtime state (tracked media paths).
     * Does NOT clear test failure injection—that's a test-time configuration.
     */
    public function reset(): void
    {
        $this->copiedMediaPaths = [];
    }

    /**
     * Get the list of copied media paths (useful for testing/inspection).
     *
     * @return array
     */
    public function getCopiedMediaPaths(): array
    {
        return $this->copiedMediaPaths;
    }
}
