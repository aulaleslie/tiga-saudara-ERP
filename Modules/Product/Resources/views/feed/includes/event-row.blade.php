@php
    $badgeClass = match($event['event_type']) {
        'product_created' => 'badge-success',
        'product_price_updated' => 'badge-info',
        'bundle_created' => 'badge-primary',
        'bundle_price_updated' => 'badge-warning',
        default => 'badge-secondary',
    };

    $typeLabel = match($event['event_type']) {
        'product_created' => 'Produk Baru',
        'product_price_updated' => 'Update Harga Produk',
        'bundle_created' => 'Paket Baru',
        'bundle_price_updated' => 'Update Harga Paket',
        default => $event['event_type'],
    };

    $bizNames = collect($event['sections'])->pluck('setting_name')->implode(', ');
@endphp

<div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 px-3 border-bottom price-feed-item"
     style="cursor: pointer;"
     role="button"
     tabindex="0"
     data-event-id="{{ $event['id'] }}"
     onclick="openPriceFeedModal({{ $event['id'] }}, this)"
     onkeydown="if(event.key==='Enter'||event.key===' '){ event.preventDefault(); openPriceFeedModal({{ $event['id'] }}, this); }">
    <div class="d-flex align-items-center pr-2">
        <div class="mr-3">
            <span class="badge {{ $badgeClass }} p-2" style="font-size: 0.85rem;">
                <i class="bi bi-tag-fill mr-1"></i> {{ $typeLabel }}
            </span>
        </div>
        <div>
            <div class="font-weight-bold text-dark mb-1" style="font-size: 0.95rem;">
                {{ $event['subject_name'] }}
                @if(!empty($event['subject_code']))
                    <span class="text-muted font-weight-normal">({{ $event['subject_code'] }})</span>
                @endif
            </div>
            <div class="small text-muted">
                <i class="bi bi-building mr-1"></i> {{ $bizNames }}
                <span class="mx-1">•</span>
                <i class="bi bi-person mr-1"></i> {{ $event['actor_name'] ?: 'System' }} ({{ $event['source'] }})
            </div>
        </div>
    </div>
    <div class="text-right pl-2" style="white-space: nowrap;">
        <span class="small text-muted font-italic">{{ $event['occurred_at_human'] }}</span>
        <i class="bi bi-chevron-right text-muted ml-2"></i>
    </div>
</div>
