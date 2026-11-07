{{-- Held custody is NOT sellable stock. Shown independently of settlement / completion status. --}}
<div class="d-flex flex-wrap gap-3 small" data-testid="custody-summary">
    <div><span class="text-muted">Diterima</span> <strong>{{ $custody['received'] }}</strong></div>
    <div><span class="text-muted">Ditahan (belum dapat dijual)</span> <strong data-testid="custody-held">{{ $custody['held'] }}</strong></div>
    <div><span class="text-muted">Dilepas ke stok</span> <strong data-testid="custody-released">{{ $custody['released'] }}</strong></div>
    <div><span class="text-muted">Dikirim ke pelanggan</span> <strong data-testid="custody-dispatched">{{ $custody['dispatched'] }}</strong></div>
</div>
