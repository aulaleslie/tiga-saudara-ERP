@extends('layouts.app')

@section('title', 'Kelola Harga Multi-Bisnis: ' . $product->product_name)

@section('content')
    <div class="container-fluid">
        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif
        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">
                <strong>Periksa kembali data yang Anda masukkan.</strong>
                <ul class="mb-0 mt-2">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="cross-business-price-form" action="{{ route('products.cross-business-prices.update', $product->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group d-flex justify-content-between">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary" id="btn-back">
                            <i class="bi bi-arrow-left"></i> Kembali
                        </a>
                        <div>
                            <button type="button" class="btn btn-warning" id="btn-edit">
                                <i class="bi bi-pencil"></i> Ubah
                            </button>
                            <button type="button" class="btn btn-secondary d-none" id="btn-cancel">
                                Batal
                            </button>
                            <button type="submit" class="btn btn-primary d-none" id="btn-save">
                                <i class="bi bi-check"></i> Simpan
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Bisnis</th>
                                            <th>Harga Jual (Rp)</th>
                                            <th>Tier 1 (Rp)</th>
                                            <th>Tier 2 (Rp)</th>
                                            <th>Harga Beli (Rp)</th>
                                            <th>Harga Beli Rata-rata (Rp)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($prices as $index => $price)
                                            <tr>
                                                <td>
                                                    {{ $price['business_name'] ?? 'Setting ' . $price['setting_id'] }}
                                                    <input type="hidden" name="prices[{{ $index }}][setting_id]" value="{{ $price['setting_id'] }}">
                                                    <input type="hidden" name="prices[{{ $index }}][version]" value="{{ $price['version'] }}">
                                                </td>
                                                @php
                                                    $normalizePrice = function($val) {
                                                        return is_numeric($val) ? round((float) $val) : $val;
                                                    };
                                                @endphp
                                                <td>
                                                    <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][sale_price]" value="{{ $normalizePrice(old('prices.'.$index.'.sale_price', $price['sale_price'])) }}" data-original="{{ $normalizePrice($price['sale_price']) }}" readonly>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][tier_1_price]" value="{{ $normalizePrice(old('prices.'.$index.'.tier_1_price', $price['tier_1_price'])) }}" data-original="{{ $normalizePrice($price['tier_1_price']) }}" readonly>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][tier_2_price]" value="{{ $normalizePrice(old('prices.'.$index.'.tier_2_price', $price['tier_2_price'])) }}" data-original="{{ $normalizePrice($price['tier_2_price']) }}" readonly>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control editable-price price-mask" name="prices[{{ $index }}][last_purchase_price]" value="{{ $normalizePrice(old('prices.'.$index.'.last_purchase_price', $price['last_purchase_price'])) }}" data-original="{{ $normalizePrice($price['last_purchase_price']) }}" readonly>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control price-mask" value="{{ $normalizePrice($price['average_purchase_price']) }}" readonly disabled>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@section('third_party_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(document).ready(function () {
            // Apply mask to all price fields
            $('.price-mask').maskMoney({
                prefix: '',
                thousands: '.',
                decimal: ',',
                precision: 0,
                allowZero: true
            });

            // Ensure masks are initialized properly with values
            $('.price-mask').each(function() {
                $(this).maskMoney('mask');
            });

            // State elements
            const $btnEdit = $('#btn-edit');
            const $btnCancel = $('#btn-cancel');
            const $btnSave = $('#btn-save');
            const $form = $('#cross-business-price-form');
            const $editableInputs = $('.editable-price');

            // Handle "Ubah" (Edit)
            $btnEdit.on('click', function () {
                $btnEdit.addClass('d-none');
                $btnCancel.removeClass('d-none');
                $btnSave.removeClass('d-none');

                // Enable inputs
                $editableInputs.prop('readonly', false);
            });

            // Handle "Batal" (Cancel)
            $btnCancel.on('click', function () {
                $btnCancel.addClass('d-none');
                $btnSave.addClass('d-none');
                $btnEdit.removeClass('d-none');

                // Revert values and make readonly
                $editableInputs.each(function () {
                    const original = $(this).data('original');
                    $(this).val(original).prop('readonly', true);
                    $(this).maskMoney('mask'); // Re-apply mask to update UI
                });
            });

            // Handle "Simpan" (Submit) protection
            $form.on('submit', function () {
                if ($btnSave.prop('disabled')) {
                    return false;
                }

                // Unmask before submit
                $('.price-mask').each(function() {
                    let val = $(this).val();
                    let unmasked = val.replace(/\./g, '');
                    $(this).val(unmasked);
                });

                $btnSave.prop('disabled', true).html('<i class="spinner-border spinner-border-sm"></i> Menyimpan...');
                $btnCancel.prop('disabled', true);
            });
        });
    </script>
@endsection
