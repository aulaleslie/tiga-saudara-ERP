<script>
    (function () {
        var toggle = document.getElementById('pos-receipt-show-note-toggle');
        var note = document.getElementById('pos-receipt-note');

        if (!toggle) {
            return;
        }

        var defaultChecked = toggle.getAttribute('data-default-checked') === '1';

        function syncNoteVisibility() {
            if (!note) {
                return;
            }

            if (toggle.checked) {
                note.hidden = false;
                note.removeAttribute('hidden');
            } else {
                note.hidden = true;
                note.setAttribute('hidden', 'hidden');
            }
        }

        function initializeFromDefault() {
            toggle.checked = defaultChecked;
            syncNoteVisibility();
        }

        initializeFromDefault();
        toggle.addEventListener('change', syncNoteVisibility);
        window.addEventListener('pageshow', initializeFromDefault);
    })();
</script>

