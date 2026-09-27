@php
    $customerId = $customerId ?? null;
    $keyPrefix = $keyPrefix ?? ($customerId ? "customer-{$customerId}" : 'standalone');
@endphp

<div data-global-pos-payments-workspace
     x-data="{
        cardFilterLoading: false,
        cardFilterLoadingTimeout: null,
        startCardFilterLoading() {
            this.cardFilterLoading = true;
            clearTimeout(this.cardFilterLoadingTimeout);
            // Last-resort error recovery only, not the normal completion path (that is
            // pos-card-filter-applied, released one animation frame after Livewire's DOM
            // morph). Summary calculation and projection filtering scan full result sets,
            // so a legitimate slow request on a large database can take longer than a
            // short timeout would allow; 60s is chosen to stay well clear of realistic
            // request durations while still recovering from a request that failed
            // silently (network error, uncaught exception) without ever dispatching
            // pos-card-filter-applied or a Livewire navigation event.
            this.cardFilterLoadingTimeout = setTimeout(() => { this.cardFilterLoading = false; }, 60000);
        },
        stopCardFilterLoading() {
            this.cardFilterLoading = false;
            clearTimeout(this.cardFilterLoadingTimeout);
        },
        livewireRequestHookCleanup: null,
        initCardFilterLoadingFailureRecovery() {
            // Explicit error-recovery path: reset immediately when any Livewire request on
            // the page fails (network error, 4xx/5xx response, uncaught exception server
            // side), rather than waiting on the timeout safeguard below. This does not
            // distinguish which component's request failed, but any in-flight failure
            // means the card-filter sequence cannot complete normally, so releasing the
            // overlay is correct.
            if (window.Livewire && typeof window.Livewire.hook === 'function') {
                this.livewireRequestHookCleanup = window.Livewire.hook('request', ({ fail }) => {
                    fail(() => this.stopCardFilterLoading());
                });
            }
        },
        destroy() {
            // Livewire.hook() registers globally and is never automatically released, so
            // without explicitly invoking the cleanup function it returns, repeated
            // mounting of this component (wire:navigate, re-rendering the workspace)
            // would accumulate one closure per mount, each still holding a reference to
            // its now-removed Alpine component and each firing on every future request
            // failure.
            clearTimeout(this.cardFilterLoadingTimeout);

            if (typeof this.livewireRequestHookCleanup === 'function') {
                this.livewireRequestHookCleanup();
            }
        }
     }"
     x-init="initCardFilterLoadingFailureRecovery()"
     x-on:pos-card-filter-loading.window="startCardFilterLoading()"
     x-on:pos-card-filter-applied.window="requestAnimationFrame(() => stopCardFilterLoading())"
     x-on:livewire:navigating.window="stopCardFilterLoading()"
>
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
    <div class="position-relative">
        <div x-show.important="cardFilterLoading" x-cloak
             class="position-absolute d-flex justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 100;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat data...</span>
            </div>
        </div>
        <livewire:pos.global-pos-payment-table
            :globalMode="true"
            :customerId="$customerId"
            :wire:key="'global-pos-table-' . $keyPrefix"
        />
    </div>
</div>
