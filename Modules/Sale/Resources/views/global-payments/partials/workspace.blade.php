@php
    $customerId = $customerId ?? null;
    $keyPrefix = $keyPrefix ?? ($customerId ? "customer-{$customerId}" : 'standalone');
@endphp

@include('livewire.partials.livewire-transport-guard')

<div data-global-sale-payments-workspace
     data-workspace-key="{{ $keyPrefix }}"
     x-data="{
        workspaceKey: @js($keyPrefix),
        cardFilterPending: false,
        cardFilterSeq: 0,
        commitHookCleanup: null,
        beginSaleCardFilter() {
            this.cardFilterSeq++;
            this.cardFilterPending = true;
            return this.cardFilterSeq;
        },
        finishSaleCardFilter(detail) {
            detail = detail || {};
            if (detail.workspace !== this.workspaceKey) return;
            // Ignore completions from older selections; only the latest ends the pending state.
            if (detail.seq !== null && detail.seq !== undefined && Number(detail.seq) !== this.cardFilterSeq) return;
            requestAnimationFrame(() => { this.cardFilterPending = false; });
        },
        failedCommitSeqs(commit) {
            // The card sequence travels in toggleCardFilter(type, seq) and in the
            // sale-filter/pos-filter dispatch params. Livewire can batch queued selections
            // into one commit, so collect every sequence the commit carries.
            const seqs = [];
            for (const call of (commit && commit.calls) || []) {
                if (call.method === 'toggleCardFilter' && call.params && call.params[1] != null) seqs.push(Number(call.params[1]));
                if (call.method === '__dispatch' && call.params && call.params[1] && call.params[1].seq != null) seqs.push(Number(call.params[1].seq));
            }
            return seqs;
        },
        init() {
            // Release only when this workspace's commit for the CURRENT card sequence fails
            // (including transport failures, see livewire-transport-guard); older or unrelated
            // failures never clear a newer selection's pending state.
            if (window.Livewire && typeof window.Livewire.hook === 'function') {
                this.commitHookCleanup = window.Livewire.hook('commit', ({ component, commit, fail }) => {
                    if (!component || !component.el || !this.$el.contains(component.el)) return;
                    const seqs = this.failedCommitSeqs(commit);
                    if (seqs.length === 0) return;
                    fail(() => { if (seqs.includes(this.cardFilterSeq)) this.cardFilterPending = false; });
                });
            }
        },
        destroy() {
            if (typeof this.commitHookCleanup === 'function') this.commitHookCleanup();
            this.commitHookCleanup = null;
        }
     }"
     x-on:sale-card-filter-applied.window="finishSaleCardFilter($event.detail)"
     x-on:livewire:navigating.window="cardFilterPending = false"
>
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
    <div class="position-relative">
        <div x-show.important="cardFilterPending" x-cloak data-sale-card-filter-loading
             class="position-absolute d-flex justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 100;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat data...</span>
            </div>
        </div>
        <livewire:sale.sale-table
            :globalMode="true"
            :customerId="$customerId"
            :wire:key="'global-sales-table-' . $keyPrefix"
        />
    </div>
</div>
