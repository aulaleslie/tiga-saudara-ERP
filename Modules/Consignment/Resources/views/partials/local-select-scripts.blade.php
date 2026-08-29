{{--
    Local (client-side) Select2 for small bounded master lists: consignment
    locations, taxes and payment terms. These sets stay small enough to render
    inline, so they need searchability but not a server round-trip.
--}}
<script>
    $(function () {
        $('.consignment-local-select').select2({
            width: '100%',
            allowClear: true,
            placeholder: function () {
                return $(this).find('option[value=""]').text() || '-- Pilih --';
            }
        });
    });
</script>
