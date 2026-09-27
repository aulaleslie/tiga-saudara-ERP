<?php

namespace Modules\Product\Services;

use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Product\Entities\ProductPriceFeedSnapshot;

class ProductPriceFeedRecorder
{
    /**
     * Record a product or bundle price event.
     *
     * @param string $eventType e.g., ProductPriceFeedEvent::TYPE_PRODUCT_CREATED
     * @param string $subjectType e.g., ProductPriceFeedEvent::SUBJECT_PRODUCT
     * @param int|null $subjectId
     * @param string $subjectName
     * @param string|null $subjectCode
     * @param array $settingSnapshots Array of ['setting_id' => int, 'setting_name' => ?string, 'before' => ?array, 'after' => ?array]
     * @param string|null $source e.g., ProductPriceFeedEvent::SOURCE_MANUAL
     * @param User|null $actor
     * @param string|null $operationUuid
     * @return ProductPriceFeedEvent|null Returns null if no-op or no snapshots to record
     */
    public function record(
        string $eventType,
        string $subjectType,
        ?int $subjectId,
        string $subjectName,
        ?string $subjectCode,
        array $settingSnapshots,
        ?string $source = null,
        ?User $actor = null,
        ?string $operationUuid = null
    ): ?ProductPriceFeedEvent {
        // Filter out no-op or empty snapshots
        $validSnapshots = [];

        foreach ($settingSnapshots as $snapshot) {
            $settingId = $snapshot['setting_id'];
            $settingName = $snapshot['setting_name'] ?? null;
            if (! $settingName) {
                $setting = Setting::find($settingId);
                $settingName = $setting ? $setting->company_name : "Business #{$settingId}";
            }

            $beforeRaw = $snapshot['before'] ?? null;
            $afterRaw = $snapshot['after'] ?? null;

            if ($eventType === ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED || $eventType === ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED) {
                $diffBefore = [];
                $diffAfter = [];

                $allKeys = array_unique(array_merge(array_keys($beforeRaw ?? []), array_keys($afterRaw ?? [])));
                foreach ($allKeys as $key) {
                    $valB = $beforeRaw[$key] ?? null;
                    $valA = $afterRaw[$key] ?? null;

                    if (! PriceFeedDecimalNormalizer::equals($valB, $valA)) {
                        $diffBefore[$key] = PriceFeedDecimalNormalizer::normalize($valB);
                        $diffAfter[$key] = PriceFeedDecimalNormalizer::normalize($valA);
                    }
                }

                if (empty($diffBefore) && empty($diffAfter)) {
                    continue; // Skip no-op snapshot for this setting
                }

                $validSnapshots[] = [
                    'setting_id' => $settingId,
                    'setting_name' => $settingName,
                    'before_snapshot' => $diffBefore,
                    'after_snapshot' => $diffAfter,
                ];
            } else {
                // Created event
                $normalizedAfter = [];
                foreach ($afterRaw ?? [] as $k => $v) {
                    $normalizedAfter[$k] = PriceFeedDecimalNormalizer::normalize($v);
                }

                $normalizedBefore = null;
                if ($beforeRaw !== null) {
                    $normalizedBefore = [];
                    foreach ($beforeRaw as $k => $v) {
                        $normalizedBefore[$k] = PriceFeedDecimalNormalizer::normalize($v);
                    }
                }

                $validSnapshots[] = [
                    'setting_id' => $settingId,
                    'setting_name' => $settingName,
                    'before_snapshot' => $normalizedBefore,
                    'after_snapshot' => $normalizedAfter,
                ];
            }
        }

        if (empty($validSnapshots)) {
            return null;
        }

        $user = $actor ?? Auth::user();
        $sourceName = $source ?? ($user ? ProductPriceFeedEvent::SOURCE_MANUAL : ProductPriceFeedEvent::SOURCE_SYSTEM);
        $actorName = $user ? ($user->name ?? $user->email) : 'System';

        $event = ProductPriceFeedEvent::create([
            'operation_uuid' => $operationUuid ?? (string) Str::uuid(),
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'subject_code' => $subjectCode,
            'user_id' => $user ? $user->id : null,
            'actor_name' => $actorName,
            'source' => $sourceName,
            'occurred_at' => now(),
        ]);

        foreach ($validSnapshots as $snap) {
            $event->snapshots()->create($snap);
        }

        return $event;
    }
}
