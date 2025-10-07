<div>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Rekonsiliasi Kas</h5>
        </div>
        <div class="card-body">
            @if (session()->has('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <form wire:submit.prevent="submit">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="recorded-sales">Penjualan Tercatat</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">{{ $currencySymbol }}</span>
                            </div>
                            <input type="number" step="0.01" min="0" id="recorded-sales" class="form-control"
                                   wire:model.lazy="recordedSalesTotal" placeholder="0.00">
                        </div>
                        @error('recordedSalesTotal')
                            <span class="text-danger small">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="cash-pickups">Total Penjemputan Kas</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">{{ $currencySymbol }}</span>
                            </div>
                            <input type="number" step="0.01" min="0" id="cash-pickups" class="form-control"
                                   wire:model.lazy="cashPickupsTotal" placeholder="0.00">
                        </div>
                        <small class="form-text text-muted">Masukkan total kas yang sudah diambil dari laci.</small>
                        @error('cashPickupsTotal')
                            <span class="text-danger small d-block">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="alert alert-info">
                    <strong>Kas yang seharusnya ada:</strong>
                    {{ $currencySymbol }} {{ number_format($expectedOnHand ?? 0, 2, ',', '.') }}
                </div>

                @include('livewire.pos.partials.denomination-table', [
                    'denominations' => $denominations,
                    'currencySymbol' => $currencySymbol,
                    'countedTotal' => $countedTotal,
                    'expectedOnHand' => $expectedOnHand,
                    'variance' => $variance,
                ])

                @error('denominations')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror

                <div class="form-group">
                    <label>Dokumen Pendukung</label>
                    @foreach($supportingDocuments as $index => $document)
                        <div class="input-group mb-2" wire:key="recon-document-{{ $index }}">
                            <input type="text" class="form-control" placeholder="Contoh: Foto kas akhir"
                                   wire:model.lazy="supportingDocuments.{{ $index }}">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-danger"
                                        wire:click="removeDocumentField({{ $index }})"
                                        {{ count($supportingDocuments) === 1 ? 'disabled' : '' }}>
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                    <button type="button" class="btn btn-link p-0" wire:click="addDocumentField">+ Tambah dokumen</button>
                </div>

                <div class="form-group">
                    <label for="recon-notes">Catatan</label>
                    <textarea id="recon-notes" rows="3" class="form-control" wire:model.lazy="notes"
                              placeholder="Catat temuan atau tindak lanjut"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Simpan Rekonsiliasi</button>
            </form>
        </div>
    </div>
</div>
