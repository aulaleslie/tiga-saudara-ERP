{{--
    Initializes every .consignment-ajax-select on the page as a debounced,
    paginated Select2. Preselected options are rendered server-side by the
    ajax-select partial, so selected values survive reloads, validation
    failures and query-string filtering without extra requests.
--}}
<script>
    $(function () {
        $('.consignment-ajax-select').each(function () {
            const select = $(this);
            const depends = JSON.parse(select.attr('data-depends') || '{}');

            select.select2({
                width: '100%',
                placeholder: select.attr('data-placeholder'),
                allowClear: true,
                minimumInputLength: parseInt(select.attr('data-min-input') || '0', 10),
                ajax: {
                    url: select.attr('data-url'),
                    dataType: 'json',
                    delay: 300,
                    data: function (params) {
                        const payload = { q: params.term, page: params.page || 1 };
                        Object.keys(depends).forEach(function (key) {
                            payload[key] = $(depends[key]).val() || '';
                        });
                        return payload;
                    },
                    processResults: data => data,
                    cache: true
                }
            });
        });
    });
</script>
