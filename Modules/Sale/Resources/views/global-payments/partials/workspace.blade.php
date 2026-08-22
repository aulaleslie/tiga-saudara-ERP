@php
    $customerId = $customerId ?? null;
    $keyPrefix = $keyPrefix ?? ($customerId ? "customer-{$customerId}" : 'standalone');
@endphp

<div data-global-sale-payments-workspace>
    <!-- Summary Cards -->
    <div class="mb-4">
        <livewire:sale.sale-summary-cards
            :globalMode="true"
            :customerId="$customerId"
            :globalBusinessFilters="request()->query('globalBusinessFilters', []) ? (is_array(request()->query('globalBusinessFilters')) ? request()->query('globalBusinessFilters') : array_filter([request()->query('globalBusinessFilters')])) : []"
            :documentDateFrom="request('documentDateFrom')"
            :documentDateTo="request('documentDateTo')"
            :dueDateFrom="request('dueDateFrom')"
            :dueDateTo="request('dueDateTo')"
            :selectedCardFilter="request('selectedCardFilter')"
            :wire:key="'global-summary-cards-' . $keyPrefix"
        />
    </div>

    <!-- Global Sales Table -->
    <livewire:sale.sale-table
        :globalMode="true"
        :customerId="$customerId"
        :wire:key="'global-sales-table-' . $keyPrefix"
    />
</div>
