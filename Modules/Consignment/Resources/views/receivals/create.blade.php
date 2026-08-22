@extends('layouts.app')

@section('title', 'Buat Dokumen Penerimaan Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.index') }}">Konsinyasi</a></li>
        <li class="breadcrumb-item active">Buat Dokumen</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid" x-data="consignmentReceivalForm()">
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('consignments.receivals.store') }}" method="POST">
            @csrf
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white font-weight-bold">
                    Informasi Header Penerimaan Konsinyasi
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="col-md-4 mb-3">
                            <label for="supplier_id">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="supplier_id" class="form-control consignment-supplier-select" required>
                                <option value="">-- Pilih Supplier --</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="date">Tanggal Dokumen <span class="text-danger">*</span></label>
                            <input type="date" name="date" id="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="supplier_delivery_reference">No. Surat Jalan / Pengiriman Supplier</label>
                            <input type="text" name="supplier_delivery_reference" id="supplier_delivery_reference" class="form-control" value="{{ old('supplier_delivery_reference') }}" placeholder="Contoh: SJ-2026/08/001">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="note">Catatan</label>
                            <textarea name="note" id="note" class="form-control" rows="2" placeholder="Catatan opsional">{{ old('note') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Lines -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold">Daftar Produk Konsinyasi</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addLine()">
                        <i class="bi bi-plus-circle"></i> Tambah Baris
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 35%;">Produk <span class="text-danger">*</span></th>
                                    <th style="width: 15%;">Jumlah <span class="text-danger">*</span></th>
                                    <th style="width: 20%;">Biaya Satuan DPP (Rp) <span class="text-danger">*</span></th>
                                    @if($setting->is_pkp)
                                        <th style="width: 15%;">Pajak <span class="text-danger">*</span></th>
                                    @endif
                                    <th style="width: 15%;">Subtotal (Rp)</th>
                                    <th style="width: 5%;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, index) in lines" :key="line.key">
                                    <tr>
                                        <td>
                                            <select :name="'lines[' + index + '][product_id]'"
                                                    class="form-control form-control-sm consignment-product-select"
                                                    x-init="$nextTick(() => initProductSelect($el, line))"
                                                    required>
                                                <option value="">-- Pilih Produk --</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" :name="'lines[' + index + '][quantity]'" class="form-control form-control-sm" x-model.number="line.quantity" step="any" min="0.001" @input="recalc(index)" required>
                                        </td>
                                        <td>
                                            <input type="number" :name="'lines[' + index + '][unit_cost]'" class="form-control form-control-sm" x-model.number="line.unit_cost" step="any" min="0.01" @input="recalc(index)" required>
                                        </td>
                                        @if($setting->is_pkp)
                                            <td>
                                                <select :name="'lines[' + index + '][tax_id]'" class="form-control form-control-sm" x-model="line.tax_id" @change="recalc(index)" required>
                                                    <option value="">-- Pilih Pajak --</option>
                                                    @foreach($taxes as $tax)
                                                        <option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}">
                                                            {{ $tax->name }} ({{ $tax->rate }}%)
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </td>
                                        @endif
                                        <td class="align-middle font-weight-bold" x-text="formatCurrency(line.subtotal_cost)"></td>
                                        <td class="text-center align-middle">
                                            <button type="button" class="btn btn-sm btn-outline-danger" @click="removeLine(index)" :disabled="lines.length === 1">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="{{ $setting->is_pkp ? 4 : 3 }}" class="text-right">Grand Total Estimasi Biaya Titipan:</th>
                                    <th class="font-weight-bold" x-text="formatCurrency(grandTotal())"></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <a href="{{ route('consignments.receivals.index') }}" class="btn btn-secondary mr-2">Batal</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Simpan Draf</button>
            </div>
        </form>
    </div>
@endsection

@push('page_scripts')
<script>
    $(function () {
        $('#supplier_id').select2({
            width: '100%',
            placeholder: '-- Cari Supplier --',
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: @json(route('consignments.receival-suppliers.search')),
                dataType: 'json',
                delay: 300,
                data: params => ({ q: params.term, page: params.page || 1 }),
                processResults: data => data,
                cache: true
            }
        });
    });

    function consignmentReceivalForm() {
        return {
            lines: [
                { key: 1, product_id: '', product_text: '', quantity: 1, unit_cost: 0, tax_id: '', subtotal_cost: 0 }
            ],
            nextLineKey: 2,
            isPkp: {{ $setting->is_pkp ? 'true' : 'false' }},
            addLine() {
                this.lines.push({ key: this.nextLineKey++, product_id: '', product_text: '', quantity: 1, unit_cost: 0, tax_id: '', subtotal_cost: 0 });
            },
            removeLine(index) {
                if (this.lines.length > 1) {
                    this.lines.splice(index, 1);
                }
            },
            productChanged(index) {
                this.recalc(index);
            },
            initProductSelect(element, line) {
                const select = $(element);

                select.select2({
                    width: '100%',
                    placeholder: '-- Cari Produk --',
                    allowClear: true,
                    minimumInputLength: 2,
                    ajax: {
                        url: @json(route('consignments.receival-products.search')),
                        dataType: 'json',
                        delay: 300,
                        data: params => ({ q: params.term, page: params.page || 1 }),
                        processResults: data => data,
                        cache: true
                    }
                });

                select.val(line.product_id ? String(line.product_id) : '').trigger('change.select2');
                select.on('change.consignment', () => {
                    line.product_id = select.val() || '';
                });
                select.on('select2:select.consignment', event => {
                    line.product_text = event.params.data.text || '';
                });
            },
            recalc(index) {
                let line = this.lines[index];
                let qty = parseFloat(line.quantity) || 0;
                let cost = parseFloat(line.unit_cost) || 0;
                line.subtotal_cost = qty * cost;
            },
            grandTotal() {
                return this.lines.reduce((acc, l) => acc + (parseFloat(l.subtotal_cost) || 0), 0);
            },
            formatCurrency(amount) {
                return 'Rp ' + Number(amount || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        };
    }
</script>
@endpush
