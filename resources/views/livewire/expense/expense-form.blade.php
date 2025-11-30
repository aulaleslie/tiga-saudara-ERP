<div>
    <form wire:submit.prevent="save" enctype="multipart/form-data">
        <input type="hidden" wire:model="idempotencyToken">
        <div class="d-flex justify-content-end mb-3">
            <button class="btn btn-primary">
                {{ $expenseId ? 'Ubah Biaya' : 'Simpan Biaya' }}
                <i class="bi bi-check"></i>
            </button>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <div class="form-row">
                    <div class="col-md-6 mb-3">
                        <label>Referensi</label>
                        <input type="text" class="form-control" wire:model="reference" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Tanggal</label>
                        <input type="date" class="form-control" wire:model="date" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="col-md-6 mb-3">
                        <label>Kategori</label>
                        <select class="form-control" wire:model="category_id" required>
                            <option value="">Pilih Kategori</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->category_name }}</option>
                            @endforeach
                        </select>
                        @error('category_id') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="mb-0">Rincian Biaya</label>
                    <button type="button" class="btn btn-outline-primary btn-sm" wire:click="addDetail">+ Tambah Rincian</button>
                </div>

                <table class="table table-bordered table-sm align-middle">
                    <thead class="thead-light">
                    <tr>
                        <th style="width: 45%">Nama</th>
                        <th style="width: 25%">Pajak</th>
                        <th style="width: 20%" class="text-end">Jumlah</th>
                        <th style="width: 10%">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($details as $index => $row)
                        <tr wire:key="detail-{{ $row['id'] ?? 'new-'.$index }}">
                            <td>
                                <input type="text" class="form-control" wire:model="details.{{ $index }}.name">
                                @error("details.$index.name") <span class="text-danger small">{{ $message }}</span> @enderror
                            </td>
                            <td>
                                <select class="form-control" wire:model="details.{{ $index }}.tax_id">
                                    <option value="">-</option>
                                    @foreach($taxes as $tax)
                                        <option value="{{ $tax->id }}">{{ $tax->name }}</option>
                                    @endforeach
                                </select>
                                @error("details.$index.tax_id") <span class="text-danger small">{{ $message }}</span> @enderror
                            </td>
                            <td>
                                <input type="text"
                                       class="form-control text-end"
                                       wire:model.defer="details.{{ $index }}.amount"
                                       wire:blur="formatAmount({{ $index }})"
                                       wire:focus="unformatAmount({{ $index }})">
                                @error("details.$index.amount") <span class="text-danger small">{{ $message }}</span> @enderror
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeDetail({{ $index }})">×</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div class="row justify-content-md-end">
                    <div class="col-md-5">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 50%">Termasuk Pajak</th>
                                    <td>
                                        <div class="form-check">
                                            <input
                                                wire:model="is_tax_included"
                                                wire:change="handleTaxIncluded"
                                                type="checkbox"
                                                class="form-check-input"
                                                id="taxIncludedCheckbox"
                                            />
                                            <input type="hidden" name="is_tax_included" value="{{ $is_tax_included ? 1 : 0 }}">
                                            <label class="form-check-label" for="taxIncludedCheckbox">Termasuk Pajak</label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Total Sebelum Pajak</th>
                                    <td>{{ $this->totalBeforeTaxFormatted }}</td>
                                </tr>
                                <tr>
                                    <th>Total Pajak</th>
                                    <td>(+) {{ $this->totalTaxFormatted }}</td>
                                </tr>
                                <tr>
                                    <th>Total Biaya</th>
                                    <th>(=) {{ $this->totalFormatted }}</th>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <label>Upload File (Opsional)</label>
                <input type="file" class="form-control" wire:model="files" multiple>
                @error('files.*') <span class="text-danger">{{ $message }}</span> @enderror
                <div wire:loading wire:target="files" class="text-info mt-2">Mengunggah file...</div>

                @if(!empty($existingAttachments))
                    <div class="mt-3">
                        <label class="form-label">Lampiran Saat Ini</label>
                        <ul class="list-group">
                            @foreach($existingAttachments as $attachment)
                                <li class="list-group-item d-flex justify-content-between align-items-center"
                                    wire:key="attachment-{{ $attachment['id'] }}">
                                    <span>
                                        {{ $attachment['name'] }}
                                        <small class="text-muted">({{ $attachment['size'] }})</small>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="removeExistingAttachment({{ $attachment['id'] }})">
                                        Hapus
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        <div class="form-group">
            <button class="btn btn-success">
                {{ $expenseId ? 'Perbarui' : 'Simpan' }}
            </button>
        </div>
    </form>
</div>
