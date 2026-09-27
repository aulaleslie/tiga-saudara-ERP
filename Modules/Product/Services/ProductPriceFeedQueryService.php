<?php

namespace Modules\Product\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Product\Entities\ProductPriceFeedSnapshot;

class ProductPriceFeedQueryService
{
    public function __construct(
        private readonly FeedPermissionResolver $permissionResolver = new FeedPermissionResolver()
    ) {
    }

    /**
     * Retrieve paginated or limited sanitized event view models.
     */
    public function getFeedEvents(
        User $user,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator|\Illuminate\Support\Collection {
        $masks = $this->permissionResolver->getSettingVisibilityMasks($user);
        $isSuperAdmin = $user->hasRole('Super Admin');

        if (! $isSuperAdmin && empty($masks)) {
            if (isset($filters['paginate']) && $filters['paginate'] === false) {
                return collect([]);
            }
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $visibleSettingIds = array_keys($masks);
        $settingFilter = ! empty($filters['setting_id']) ? (int) $filters['setting_id'] : null;

        $query = ProductPriceFeedEvent::query()
            ->with(['snapshots']);

        if (! $isSuperAdmin) {
            if ($settingFilter !== null && ! in_array($settingFilter, $visibleSettingIds, true)) {
                if (isset($filters['paginate']) && $filters['paginate'] === false) {
                    return collect([]);
                }
                return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
            }

            $query->whereHas('snapshots', function ($q) use ($masks, $settingFilter) {
                $q->where(function ($subQ) use ($masks, $settingFilter) {
                    foreach ($masks as $settingId => $mask) {
                        if ($settingFilter !== null && $settingId !== $settingFilter) {
                            continue;
                        }

                        $subQ->orWhere(function ($settingQ) use ($settingId, $mask) {
                            $settingQ->where('setting_id', $settingId);

                            $settingQ->whereHas('event', function ($eQ) use ($mask) {
                                $eQ->where(function ($eventQ) use ($mask) {
                                    if (! $mask['can_bundle_event']) {
                                        $eventQ->whereNotIn('event_type', [
                                            ProductPriceFeedEvent::TYPE_BUNDLE_CREATED,
                                            ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED,
                                        ]);
                                    }

                                    // Field-level filtering for product events
                                    if (! $mask['can_purchase_price'] && $mask['can_sales_prices']) {
                                        // User can only see sales prices -> exclude events where tracked fields are purchase-only
                                        $eventQ->whereRaw("(JSON_EXTRACT(after_snapshot, '$.sale_price') IS NOT NULL OR JSON_EXTRACT(after_snapshot, '$.tier_1_price') IS NOT NULL OR JSON_EXTRACT(after_snapshot, '$.tier_2_price') IS NOT NULL OR JSON_EXTRACT(after_snapshot, '$.bundle_sale_price') IS NOT NULL)");
                                    } elseif ($mask['can_purchase_price'] && ! $mask['can_sales_prices']) {
                                        // User can only see purchase prices -> exclude events where tracked fields are sales-only
                                        $eventQ->whereRaw("JSON_EXTRACT(after_snapshot, '$.last_purchase_price') IS NOT NULL");
                                    }
                                });
                            });
                        });
                    }
                });
            });
        } elseif ($settingFilter !== null) {
            $query->whereHas('snapshots', function ($q) use ($settingFilter) {
                $q->where('setting_id', $settingFilter);
            });
        }

        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (! empty($filters['start_date'])) {
            $query->where('occurred_at', '>=', $filters['start_date'] . ' 00:00:00');
        }

        if (! empty($filters['end_date'])) {
            $query->where('occurred_at', '<=', $filters['end_date'] . ' 23:59:59');
        }

        // Tokenized partial search across subject_name, subject_code
        if (! empty($filters['search'])) {
            $searchRaw = trim($filters['search']);
            $tokens = array_filter(explode(' ', $searchRaw));

            if (! empty($tokens)) {
                $query->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $q->where(function ($subQ) use ($token) {
                            $term = '%' . mb_strtolower($token) . '%';
                            $subQ->whereRaw('LOWER(subject_name) LIKE ?', [$term])
                                 ->orWhereRaw('LOWER(subject_code) LIKE ?', [$term]);
                        });
                    }
                });
            }
        }

        $query->orderBy('occurred_at', 'desc')->orderBy('id', 'desc');

        if (isset($filters['limit'])) {
            $rawEvents = $query->take($filters['limit'])->get();
            return $this->sanitizeEvents($rawEvents, $masks, $isSuperAdmin);
        }

        if (isset($filters['paginate']) && $filters['paginate'] === false) {
            $rawEvents = $query->get();
            return $this->sanitizeEvents($rawEvents, $masks, $isSuperAdmin);
        }

        $paginator = $query->paginate($perPage);
        $sanitizedItems = $this->sanitizeEvents(collect($paginator->items()), $masks, $isSuperAdmin);

        return $paginator->setCollection($sanitizedItems);
    }

    /**
     * Retrieve single event detail by ID for modal viewing.
     */
    public function getEventDetail(User $user, int $eventId): ?array
    {
        $masks = $this->permissionResolver->getSettingVisibilityMasks($user);
        $isSuperAdmin = $user->hasRole('Super Admin');

        $event = ProductPriceFeedEvent::with('snapshots')->find($eventId);
        if (! $event) {
            return null;
        }

        $sanitized = $this->sanitizeEvent($event, $masks, $isSuperAdmin);
        if (! $sanitized || empty($sanitized['sections'])) {
            return null;
        }

        return $sanitized;
    }

    /**
     * Get available business filter options for the user.
     */
    public function getVisibleBusinesses(User $user): array
    {
        $masks = $this->permissionResolver->getSettingVisibilityMasks($user);
        if ($user->hasRole('Super Admin')) {
            return \Modules\Setting\Entities\Setting::query()
                ->select('id', 'company_name')
                ->get()
                ->toArray();
        }

        if (empty($masks)) {
            return [];
        }

        return \Modules\Setting\Entities\Setting::query()
            ->whereIn('id', array_keys($masks))
            ->select('id', 'company_name')
            ->get()
            ->toArray();
    }

    private function sanitizeEvents($rawEvents, array $masks, bool $isSuperAdmin): \Illuminate\Support\Collection
    {
        $result = collect();
        foreach ($rawEvents as $event) {
            $sanitized = $this->sanitizeEvent($event, $masks, $isSuperAdmin);
            if ($sanitized && ! empty($sanitized['sections'])) {
                $result->push($sanitized);
            }
        }
        return $result;
    }

    private function sanitizeEvent(ProductPriceFeedEvent $event, array $masks, bool $isSuperAdmin): ?array
    {
        $sections = [];

        foreach ($event->snapshots as $snap) {
            $settingId = (int) $snap->setting_id;
            if (! $isSuperAdmin && ! isset($masks[$settingId])) {
                continue; // User has no access to this business
            }

            $mask = $masks[$settingId] ?? [
                'can_purchase_price' => true,
                'can_sales_prices' => true,
                'can_bundle_event' => true,
            ];

            // If it's a bundle event and user cannot view bundle events in this setting, skip
            if (str_contains($event->event_type, 'bundle') && ! $mask['can_bundle_event']) {
                continue;
            }

            $beforeSanitized = $this->sanitizeSnapshotArray($snap->before_snapshot, $mask);
            $afterSanitized = $this->sanitizeSnapshotArray($snap->after_snapshot, $mask);

            // If both before and after are empty after masking, skip this section
            if (empty($beforeSanitized) && empty($afterSanitized)) {
                continue;
            }

            $sections[] = [
                'setting_id' => $settingId,
                'setting_name' => $snap->setting_name,
                'before' => $beforeSanitized,
                'after' => $afterSanitized,
            ];
        }

        if (empty($sections)) {
            return null;
        }

        return [
            'id' => $event->id,
            'operation_uuid' => $event->operation_uuid,
            'event_type' => $event->event_type,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'subject_name' => $event->subject_name,
            'subject_code' => $event->subject_code,
            'actor_name' => $event->actor_name,
            'source' => $event->source,
            'occurred_at' => $event->occurred_at->format('Y-m-d H:i:s'),
            'occurred_at_human' => $event->occurred_at->diffForHumans(),
            'sections' => $sections,
        ];
    }

    private function sanitizeSnapshotArray(?array $snapshot, array $mask): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $sanitized = [];
        foreach ($snapshot as $key => $val) {
            if ($key === 'last_purchase_price') {
                if ($mask['can_purchase_price']) {
                    $sanitized[$key] = $val;
                }
            } elseif (in_array($key, ['sale_price', 'tier_1_price', 'tier_2_price', 'bundle_sale_price'], true)) {
                if ($mask['can_sales_prices']) {
                    $sanitized[$key] = $val;
                }
            } else {
                $sanitized[$key] = $val;
            }
        }

        return $sanitized;
    }
}
