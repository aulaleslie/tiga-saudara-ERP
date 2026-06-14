<div>
    <form enctype="multipart/form-data">
        <input type="hidden" wire:model="idempotencyToken">
        <div class="d-flex justify-content-end mb-3 gap-2">
            <button
                type="button"
                class="btn btn-secondary me-2"
                wire:click="saveDraft"
                wire:loading.attr="disabled"
                wire:target="saveDraft,submitForApproval"
            >
                <span wire:loading.remove wire:target="saveDraft">
                    Simpan Draft
                </span>
                <span wire:loading wire:target="saveDraft">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Memproses...
                </span>
            </button>
            <button
                type="button"
                class="btn btn-primary"
                wire:click="submitForApproval"
                wire:loading.attr="disabled"
                wire:target="saveDraft,submitForApproval"
            >
                <span wire:loading.remove wire:target="submitForApproval">
                    Ajukan Persetujuan
                    <i class="bi bi-check"></i>
                </span>
                <span wire:loading wire:target="submitForApproval">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Memproses...
                </span>
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
                        <div class="input-group">
                            <select class="form-control" wire:model="category_id" required>
                                <option value="">Pilih Kategori</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->category_name }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-secondary" type="button" wire:click="$dispatch('openExpenseCategoryModal', { requester: 'expense-form' })">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
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
                        @if($is_pkp)
                            <th style="width: 25%">Pajak</th>
                        @endif
                        <th style="width: {{ $is_pkp ? '20%' : '45%' }}" class="text-end">Jumlah</th>
                        <th style="width: 10%">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($details as $index => $row)
                        <tr wire:key="detail-{{ $row['id'] ?? 'new-'.$index }}">
                            <td>
                                <div x-data="{
                                        open: false,
                                        search: @entangle('details.'.$index.'.name'),
                                        suggestions: [],
                                        async fetchSuggestions() {
                                            if (this.search && this.search.length > 0) {
                                                let res = await $wire.getSuggestions(this.search);
                                                this.suggestions = res;
                                                this.open = this.suggestions.length > 0;
                                            } else {
                                                this.suggestions = [];
                                                this.open = false;
                                            }
                                        },
                                        selectItem(name) {
                                            this.search = name;
                                            this.open = false;
                                        }
                                    }" 
                                    @click.away="open = false" 
                                    class="position-relative"
                                >
                                    <input type="text" class="form-control" x-model="search" @input.debounce.300ms="fetchSuggestions" @focus="fetchSuggestions">
                                    
                                    <ul x-show="open" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1000; max-height: 200px; overflow-y: auto; display: none;">
                                        <template x-for="suggestion in suggestions" :key="suggestion">
                                            <li class="list-group-item list-group-item-action px-3 py-2" style="cursor: pointer;" @click="selectItem(suggestion)" x-text="suggestion"></li>
                                        </template>
                                    </ul>
                                </div>
                                @error("details.$index.name") <span class="text-danger small">{{ $message }}</span> @enderror
                            </td>
                            @if($is_pkp)
                            <td>
                                <div class="input-group">
                                    <select class="form-control" wire:model="details.{{ $index }}.tax_id">
                                        <option value="">-</option>
                                        @foreach($taxes as $tax)
                                            <option value="{{ $tax->id }}">{{ $tax->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-outline-secondary" type="button" wire:click="$dispatch('openTaxModal', { requester: 'expense-form', product_id: {{ $index }} })">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                @error("details.$index.tax_id") <span class="text-danger small">{{ $message }}</span> @enderror
                            </td>
                            @endif
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
                                @if($is_pkp)
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
                                @endif
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

        <div class="form-group d-flex gap-2">
            <button
                type="button"
                class="btn btn-secondary me-2"
                wire:click="saveDraft"
                wire:loading.attr="disabled"
                wire:target="saveDraft,submitForApproval"
            >
                <span wire:loading.remove wire:target="saveDraft">
                    Simpan Draft
                </span>
                <span wire:loading wire:target="saveDraft">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Memproses...
                </span>
            </button>
            <button
                type="button"
                class="btn btn-success"
                wire:click="submitForApproval"
                wire:loading.attr="disabled"
                wire:target="saveDraft,submitForApproval"
            >
                <span wire:loading.remove wire:target="submitForApproval">
                    Ajukan Persetujuan
                </span>
                <span wire:loading wire:target="submitForApproval">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Memproses...
                </span>
            </button>
        </div>
    </form>
    
    @livewire('expense.expense-category-quick-add-modal')
    @livewire('modules.setting.modals.tax-quick-add-modal')
</div>
