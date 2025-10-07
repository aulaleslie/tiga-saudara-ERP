<div>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Penjemputan Kas</h5>
        </div>
        <div class="card-body">
            @if (session()->has('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <form wire:submit.prevent="submit">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="pickup-amount">Nominal Penjemputan</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">{{ $currencySymbol }}</span>
                            </div>
                            <input type="number" step="0.01" min="0" id="pickup-amount" class="form-control"
                                   wire:model.lazy="pickupAmount" placeholder="0.00">
                        </div>
                        @error('pickupAmount')
                            <span class="text-danger small">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="recipient">Petugas / Penerima</label>
                        <input type="text" id="recipient" class="form-control" wire:model.lazy="recipient"
                               placeholder="Nama petugas penjemput">
                        @error('recipient')
                            <span class="text-danger small">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="form-group">
                    <label for="reference">Referensi / Dokumen</label>
                    <input type="text" id="reference" class="form-control" wire:model.lazy="reference"
                           placeholder="Contoh: Nomor berita acara">
                    @error('reference')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                </div>

                @include('livewire.pos.partials.denomination-table', [
                    'denominations' => $denominations,
                    'currencySymbol' => $currencySymbol,
                    'countedTotal' => $countedTotal,
                    'expectedTotal' => $pickupAmount,
                    'variance' => $variance,
                ])

                <div class="form-group">
                    <label>Dokumen Pendukung</label>
                    @foreach($supportingDocuments as $index => $document)
                        <div class="input-group mb-2" wire:key="pickup-document-{{ $index }}">
                            <input type="text" class="form-control" placeholder="Contoh: Foto tas uang"
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
                    <label for="pickup-notes">Catatan</label>
                    <textarea id="pickup-notes" rows="3" class="form-control" wire:model.lazy="notes"
                              placeholder="Catat tujuan atau detail penjemputan"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Simpan Penjemputan Kas</button>
            </form>
        </div>
    </div>
</div>
