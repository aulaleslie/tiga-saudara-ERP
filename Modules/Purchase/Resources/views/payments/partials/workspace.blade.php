@php
    $supplierId = $supplierId ?? null;
    $keyPrefix = $keyPrefix ?? ($supplierId ? "supplier-{$supplierId}" : 'standalone');
    $projectionProduct = $projectionProduct ?? null;
@endphp

<div data-global-purchase-payments-workspace>
    @unless($projectionProduct)
        <livewire:purchase.purchase-summary-cards
            :globalMode="true"
            :supplierId="$supplierId"
            :globalBusinessFilters="request()->query('globalBusinessFilters', []) ? (is_array(request()->query('globalBusinessFilters')) ? request()->query('globalBusinessFilters') : array_filter([request()->query('globalBusinessFilters')])) : []"
            :documentDateFrom="request('documentDateFrom')"
            :documentDateTo="request('documentDateTo')"
            :dueDateFrom="request('dueDateFrom')"
            :dueDateTo="request('dueDateTo')"
            :selectedCardFilter="request('selectedCardFilter')"
            :wire:key="'global-summary-cards-' . $keyPrefix"
        />
    @endunless

    <livewire:purchase.purchase-table
        :globalMode="true"
        :supplierId="$supplierId"
        :projectionProductId="$projectionProduct?->id"
        :wire:key="'global-purchases-table-' . $keyPrefix . '-' . ($projectionProduct?->id ?? 'all')"
    />
</div>
