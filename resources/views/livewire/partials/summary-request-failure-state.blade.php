{{--
    Alpine state for a deferred summary component root: tracks failed summary requests client-side,
    covering failures that never reach PHP (transport errors, proxy timeouts, 5xx), which cannot set
    the server-side summaryError. Cleared only when a later summary request succeeds.
--}}
x-data="{
    summaryRequestFailed: false,
    summaryHookCleanup: null,
    isSummaryCommit(commit) {
        return ((commit && commit.calls) || []).some((call) =>
            ['loadSummary', 'retrySummary'].includes(call.method)
            || (call.method === '__dispatch' && call.params && /-filters-changed$/.test(call.params[0] || '')));
    },
    init() {
        const root = this.$el;
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') return;
        this.summaryHookCleanup = window.Livewire.hook('commit', ({ component, commit, fail, succeed }) => {
            if (!component || component.el !== root || !this.isSummaryCommit(commit)) return;
            fail(() => { this.summaryRequestFailed = true; });
            succeed(() => { this.summaryRequestFailed = false; });
        });
    },
    destroy() {
        if (typeof this.summaryHookCleanup === 'function') this.summaryHookCleanup();
        this.summaryHookCleanup = null;
    }
}"
