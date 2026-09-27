{{-- Explicit confirmation: the only path that approves and dispatches. Same operation key in the modal and the fallback, so a repeat submit is deduplicated. --}}
<form action="{{ route('transfers.v3.approve', $transfer) }}" method="POST" class="d-inline">
    @csrf
    <input type="hidden" name="request_revision_id" value="{{ $summary['request_revision_id'] }}">
    <input type="hidden" name="configuration_revision" value="{{ $summary['configuration_revision'] }}">
    <input type="hidden" name="operation_key" value="{{ $operationKey }}">
    <button type="submit" class="btn btn-success" onclick="this.disabled=true; this.form.submit();">Konfirmasi Setujui dan Kirim</button>
</form>
