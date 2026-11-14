{{--
    Nominal-field formatting shared with the consignment billing conversion screen (same parsing and display rules:
    Indonesian thousand-separated display, raw value while editing, canonical dot-decimal value submitted). Exposed as
    window.consignmentNominal so the buyout wizard can reuse it without changing the conversion screen.
--}}
<script>
    window.consignmentNominal = window.consignmentNominal || (function () {
        function pattern(precision) {
            const idFrac = precision > 0 ? `(,\\d{1,${precision}})?` : '';
            const canonicalFrac = precision > 0 ? `(\\.\\d{1,${precision}})?` : '';
            return {
                idPattern: new RegExp(`^\\d{1,3}(\\.\\d{3})*${idFrac}$|^\\d+${idFrac}$`),
                canonicalPattern: new RegExp(`^\\d+${canonicalFrac}$`),
            };
        }
        // '' for empty, null when unparsable (never silently changes the amount), else the canonical string.
        function parse(val, precision) {
            if (val === null || val === undefined) return '';
            const str = String(val).trim();
            if (str === '') return '';
            const { idPattern, canonicalPattern } = pattern(precision);
            if (idPattern.test(str)) {
                const num = parseFloat(str.replace(/\./g, '').replace(',', '.'));
                return isNaN(num) ? null : num.toFixed(precision);
            }
            if (canonicalPattern.test(str)) {
                const num = parseFloat(str);
                return isNaN(num) ? null : num.toFixed(precision);
            }
            return null;
        }
        function format(val, precision) {
            if (val === null || val === undefined || val === '') return '';
            const num = parseFloat(val);
            if (isNaN(num)) return String(val);
            const parts = num.toFixed(precision).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return parts.length > 1 ? parts.join(',') : parts[0];
        }
        function raw(val, precision) {
            if (val === null || val === undefined || val === '') return '';
            const num = parseFloat(val);
            if (isNaN(num)) return String(val);
            return num % 1 === 0 ? String(Math.round(num)) : num.toFixed(precision).replace(/0+$/, '').replace(/\.$/, '');
        }
        return { parse, format, raw };
    })();
</script>
