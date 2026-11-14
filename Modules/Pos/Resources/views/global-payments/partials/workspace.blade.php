@php
    $customerId = $customerId ?? null;
    $keyPrefix = $keyPrefix ?? ($customerId ? "customer-{$customerId}" : 'standalone');
@endphp

@include('livewire.partials.livewire-transport-guard')

<div data-global-pos-payments-workspace
     data-workspace-key="{{ $keyPrefix }}"
     x-data="{
        workspaceKey: @js($keyPrefix),
        cardFilterLoading: false,
        cardFilterSeq: 0,
        commitHookCleanup: null,
        beginPosCardFilter() {
            // Pending starts on click and ends only when this workspace's table reports the
            // latest selection applied (pos-card-filter-applied) or one of this workspace's
            // own component requests fails. No fixed timer marks completion.
            this.cardFilterSeq++;
            this.cardFilterLoading = true;
            return this.cardFilterSeq;
        },
        finishPosCardFilter(detail) {
            detail = detail || {};
            if (detail.workspace !== this.workspaceKey) return;
            if (detail.seq !== null && detail.seq !== undefined && Number(detail.seq) !== this.cardFilterSeq) return;
            requestAnimationFrame(() => { this.cardFilterLoading = false; });
        },
        stopCardFilterLoading() {
            this.cardFilterLoading = false;
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
            // (including transport failures, see livewire-transport-guard).
            if (window.Livewire && typeof window.Livewire.hook === 'function') {
                this.commitHookCleanup = window.Livewire.hook('commit', ({ component, commit, fail }) => {
                    if (!component || !component.el || !this.$el.contains(component.el)) return;
                    const seqs = this.failedCommitSeqs(commit);
                    if (seqs.length === 0) return;
                    fail(() => { if (seqs.includes(this.cardFilterSeq)) this.stopCardFilterLoading(); });
                });
            }
        },
        destroy() {
            // Livewire.hook() registers globally; release it so remounts do not accumulate closures.
            if (typeof this.commitHookCleanup === 'function') this.commitHookCleanup();
            this.commitHookCleanup = null;
        }
     }"
     x-on:pos-card-filter-applied.window="finishPosCardFilter($event.detail)"
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
