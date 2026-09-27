<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Services\Notification\NotificationActionUrlNormalizer;
use Illuminate\Console\Command;

class RepairNotificationActionUrlsCommand extends Command
{
    protected $signature = 'notifications:repair-action-urls
        {--origin=* : Allowed legacy origins to normalize (e.g. http://192.168.1.100:8000, https://erp.domain.com)}
        {--apply : Persist changes instead of preview/dry-run}
        {--chunk=500 : Chunk size for scanning rows}';

    protected $description = 'Idempotently repair legacy absolute notification action URLs matching allowed origins into origin-relative paths';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));
        $rawOrigins = (array) $this->option('origin');

        $allowedOrigins = array_values(array_filter(array_map('trim', $rawOrigins)));

        $this->info(sprintf(
            'Running Notification Action URL Repair in %s mode (chunk: %d)',
            $apply ? 'APPLY' : 'PREVIEW/DRY-RUN',
            $chunk
        ));

        if (empty($allowedOrigins)) {
            $this->warn('No --origin options provided. Absolute URLs will NOT match any recognized origin and will be marked as unrecognized.');
        } else {
            $this->line('Allowed origins: ' . implode(', ', $allowedOrigins));
        }

        $stats = [
            'scanned' => 0,
            'already_relative' => 0,
            'empty_or_placeholder' => 0,
            'repairable' => 0,
            'repaired' => 0,
            'unrecognized_origin' => 0,
            'malformed_or_invalid' => 0,
        ];

        $unrecognizedExamples = [];

        Notification::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($notifications) use ($allowedOrigins, $apply, &$stats, &$unrecognizedExamples) {
                foreach ($notifications as $notification) {
                    $stats['scanned']++;
                    $actionUrl = $notification->action_url;

                    if ($actionUrl === null || trim($actionUrl) === '' || trim($actionUrl) === '#') {
                        $stats['empty_or_placeholder']++;
                        continue;
                    }

                    $trimmed = trim($actionUrl);

                    // Check if already relative (starts with / and not //)
                    if (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '/\\')) {
                        $stats['already_relative']++;
                        continue;
                    }

                    // Attempt normalization with allowed origins
                    $normalized = NotificationActionUrlNormalizer::normalize($trimmed, $allowedOrigins);

                    if ($normalized !== null) {
                        // It matched an allowed origin and produced an origin-relative path!
                        $stats['repairable']++;

                        if ($apply) {
                            // Update only action_url without touching other columns or updating timestamps
                            $notification->timestamps = false;
                            $notification->action_url = $normalized;
                            $notification->saveQuietly();
                            $notification->timestamps = true;
                            $stats['repaired']++;
                        }
                    } else {
                        // Check why it failed
                        $parsed = parse_url($trimmed);
                        if ($parsed !== false && (isset($parsed['scheme']) || isset($parsed['host']))) {
                            $stats['unrecognized_origin']++;
                            if (count($unrecognizedExamples) < 5) {
                                $unrecognizedExamples[] = [
                                    'id' => $notification->id,
                                    'action_url' => $trimmed,
                                ];
                            }
                        } else {
                            $stats['malformed_or_invalid']++;
                        }
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Notifications Scanned', $stats['scanned']],
                ['Already Origin-Relative', $stats['already_relative']],
                ['Empty or Placeholder (#)', $stats['empty_or_placeholder']],
                ['Repairable Rows', $stats['repairable']],
                ['Repaired Rows (persisted)', $stats['repaired']],
                ['Unrecognized External Origins', $stats['unrecognized_origin']],
                ['Malformed or Invalid URLs', $stats['malformed_or_invalid']],
            ]
        );

        if (!empty($unrecognizedExamples)) {
            $this->newLine();
            $this->warn('Sample unrecognized origin notification rows (use --origin to recognize):');
            foreach ($unrecognizedExamples as $example) {
                $this->line(sprintf('  - [ID: %d] %s', $example['id'], $example['action_url']));
            }
        }

        if (!$apply && $stats['repairable'] > 0) {
            $this->info("Preview complete. To execute changes, run command with '--apply'.");
        } elseif ($apply) {
            $this->info("Repair successfully applied.");
        }

        return 0;
    }
}
