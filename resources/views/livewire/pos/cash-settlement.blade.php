<div>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Penyetoran Kas</h5>
        </div>
        <div class="card-body">
            @if (session()->has('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <form wire:submit.prevent="submit">
                <div class="form-group">
                    <label for="expected-total">Total Penjualan Tercatat</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">{{ $currencySymbol }}</span>
                        </div>
                        <input type="number" step="0.01" min="0" id="expected-total" class="form-control"
                               placeholder="0.00" wire:model.lazy="expectedTotal">
                    </div>
                    <small class="form-text text-muted">Masukkan total penjualan menurut sistem untuk sesi ini.</small>
                    @error('expectedTotal')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>

                @include('livewire.pos.partials.denomination-table', [
                    'denominations' => $denominations,
                    'currencySymbol' => $currencySymbol,
                    'countedTotal' => $countedTotal,
                    'expectedTotal' => $expectedTotal,
                    'variance' => $variance,
                ])

                @error('denominations')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror

                <div class="form-group">
                    <label>Dokumen Pendukung</label>
                    @foreach($supportingDocuments as $index => $document)
                        <div class="input-group mb-2" wire:key="document-{{ $index }}">
                            <input type="text" class="form-control" placeholder="Contoh: Slip setoran bank"
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
                    <label for="notes">Catatan Tambahan</label>
                    <textarea id="notes" rows="3" class="form-control" wire:model.lazy="notes"
                              placeholder="Catat detail penting lainnya"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Simpan Penyetoran Kas</button>
            </form>
        </div>
    </div>
</div>
