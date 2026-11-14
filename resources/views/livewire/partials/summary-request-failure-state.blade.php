{{--
    Alpine state for a deferred summary component root: tracks failed summary requests client-side,
    covering failures that never reach PHP (transport errors, proxy timeouts, 5xx), which cannot set
    the server-side summaryError. Cleared only when a later summary request succeeds.

    A failed *-filters-changed request never reached PHP, so the component snapshot still holds the
    old scope. The latest filter inputs from such a failure are retained in failedSummaryFilters and
    replayed by retrySummary(retryInputs()), so the retry calculates the scope the table shows. The
    failed event's card selection is replayed unless a newer card selection superseded it.
    After a failed initial load, only a successful loadSummary/retrySummary clears the warning.
--}}
x-data="{
    summaryRequestFailed: false,
    failedSummaryFilters: null,
    initialLoadFailed: false,
    selectionSuperseded: false,
    summaryHookCleanup: null,
    summaryCalls(commit) {
        return ((commit && commit.calls) || []).filter((call) =>
            ['loadSummary', 'retrySummary'].includes(call.method)
            || (call.method === '__dispatch' && call.params && /-filters-changed$/.test(call.params[0] || '')));
    },
    latestFilterInputs(calls) {
        let latest = null;
        for (const call of calls) {
            if (call.method === '__dispatch' && call.params && call.params[1]) latest = call.params[1];
            if (call.method === 'retrySummary' && call.params && call.params[0]) latest = call.params[0];
        }
        return latest;
    },
    retryInputs() {
        if (!this.failedSummaryFilters) return null;
        const inputs = Object.assign({}, this.failedSummaryFilters);
        if (this.selectionSuperseded) delete inputs.selectedCardFilter;
        return inputs;
    },
    init() {
        const root = this.$el;
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') return;
        this.summaryHookCleanup = window.Livewire.hook('commit', ({ component, commit, fail, succeed }) => {
            if (!component || component.el !== root) return;
            // A card selection that SUCCEEDS after the failure is newer than the failed event's
            // selection. A card request that itself fails changed nothing on the server, so it
            // must not supersede the failed event's selection.
            if (((commit && commit.calls) || []).some((call) => call.method === 'toggleCardFilter')) {
                succeed(() => { if (this.failedSummaryFilters) this.selectionSuperseded = true; });
            }
            const calls = this.summaryCalls(commit);
            if (calls.length === 0) return;
            const inputs = this.latestFilterInputs(calls);
            const calculates = calls.some((call) => ['loadSummary', 'retrySummary'].includes(call.method));
            const isInitialLoad = calls.some((call) => call.method === 'loadSummary');
            fail(() => {
                if (isInitialLoad) this.initialLoadFailed = true;
                if (inputs) {
                    this.failedSummaryFilters = JSON.parse(JSON.stringify(inputs));
                    this.selectionSuperseded = false;
                }
                this.summaryRequestFailed = true;
            });
            succeed(() => {
                // The filter inputs now reached PHP, so they no longer need replaying.
                this.failedSummaryFilters = null;
                this.selectionSuperseded = false;
                // After a failed initial load the component is not initialized and a filter event
                // only stores inputs without calculating: keep the retry until a calculation succeeds.
                if (!calculates && this.initialLoadFailed) return;
                this.summaryRequestFailed = false;
                this.initialLoadFailed = false;
            });
        });
    },
    destroy() {
        if (typeof this.summaryHookCleanup === 'function') this.summaryHookCleanup();
        this.summaryHookCleanup = null;
    }
}"
