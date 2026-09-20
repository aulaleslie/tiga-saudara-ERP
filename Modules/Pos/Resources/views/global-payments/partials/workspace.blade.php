@php
    $customerId = $customerId ?? null;
    $keyPrefix = $keyPrefix ?? ($customerId ? "customer-{$customerId}" : 'standalone');
@endphp

<div data-global-pos-payments-workspace>
    <!-- Summary Cards -->
    <div class="mb-4">
        <livewire:pos.pos-summary-cards
            :globalMode="true"
            :customerId="$customerId"
            :globalBusinessFilters="request()->query('globalBusinessFilters', []) ? (is_array(request()->query('globalBusinessFilters')) ? request()->query('globalBusinessFilters') : array_filter([request()->query('globalBusinessFilters')])) : []"
            :transactionDateFrom="request('transactionDateFrom')"
            :transactionDateTo="request('transactionDateTo')"
            :dueDateFrom="request('dueDateFrom')"
            :dueDateTo="request('dueDateTo')"
            :selectedCardFilter="request('selectedCardFilter')"
            :wire:key="'global-pos-summary-cards-' . $keyPrefix"
        />
    </div>

    <!-- Global POS Transactions Table -->
    <livewire:pos.global-pos-payment-table
        :globalMode="true"
        :customerId="$customerId"
        :wire:key="'global-pos-table-' . $keyPrefix"
    />
</div>
