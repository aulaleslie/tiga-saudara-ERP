<script>
    document.addEventListener('DOMContentLoaded', function () {
        var feedback = document.getElementById('contact-status-feedback');
        var tableId = @json($tableId);

        document.addEventListener('submit', async function (event) {
            var form = event.target.closest('.contact-status-form');
            if (!form) return;
            event.preventDefault();

            var active = form.dataset.contactActive === '1';
            var verb = active ? 'Nonaktifkan' : 'Aktifkan kembali';
            var detail = active ? ' ' + form.dataset.contactConfirm : '';
            if (!window.confirm(verb + ' ' + form.dataset.contactLabel + ' "' + form.dataset.contactName + '"?' + detail)) return;

            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                var response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form),
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                });
                var payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Status gagal diperbarui.');

                feedback.textContent = payload.message;
                feedback.className = 'alert alert-' + (payload.is_active ? 'success' : 'info');
                var table = window.LaravelDataTables[tableId];
                table.ajax.reload(null, false);
            } catch (error) {
                feedback.textContent = error.message || 'Status gagal diperbarui.';
                feedback.className = 'alert alert-danger';
                button.disabled = false;
            }
        });
    });
</script>
