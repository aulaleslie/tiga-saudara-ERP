<!-- Bundle Details Modal -->
<div wire:ignore.self class="modal fade" id="bundleDetailsModal" tabindex="-1" role="dialog" aria-labelledby="bundleDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bundleDetailsModalLabel">Detail Paket Penjualan</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Nama Paket:</strong> {{ $selectedBundle['name'] }}
                    </div>
                    <div class="col-md-6 text-md-right">
                        <strong>Harga Paket:</strong> {{ format_currency($selectedBundle['price']) }}
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="thead-light">
                            <tr>
                                <th>Nama Barang</th>
                                <th class="text-center">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($selectedBundle['items'] as $item)
                                <tr>
                                    <td>{{ $item['name'] }}</td>
                                    <td class="text-center">{{ $item['quantity'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="text-center">Tidak ada item dalam paket ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('livewire:initialized', () => {
        @this.on('open-bundle-modal', (event) => {
            $('#bundleDetailsModal').modal('show');
        });
    });
</script>
