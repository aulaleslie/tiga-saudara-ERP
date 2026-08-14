@if(session('lifecycle_warning'))
<script>
    document.addEventListener('DOMContentLoaded', async function () {
        const warningData = @json(session('lifecycle_warning'));
        if (!warningData) return;

        const helper = (typeof window !== 'undefined' && window.BundleLifecycleWarning)
            || (typeof BundleLifecycleWarning !== 'undefined' ? BundleLifecycleWarning : null);

        if (!helper || typeof helper.handleSalesLifecycleWarning !== 'function') {
            console.error('BundleLifecycleWarning helper is unavailable.');
            return;
        }

        await helper.handleSalesLifecycleWarning(warningData, {
            csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
        });
    });
</script>
@endif
