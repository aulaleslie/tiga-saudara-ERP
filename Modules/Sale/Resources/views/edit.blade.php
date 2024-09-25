@extends('layouts.app')

@section('title', 'Edit Sale')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Sales</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')
                        <form id="sale-form" action="{{ route('sales.update', $sale) }}" method="POST">
                            @csrf
                            @method('patch')
                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="reference">Reference <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required value="{{ $sale->reference }}" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="tags">Tags <span class="text-danger">*</span></label>
                                        <select class="form-control" name="tags[]" id="tags" required>
                                            <option value="1">Dummy Tag 1</option>
                                            <option value="2">Dummy Tag 2</option>
                                            <option value="3">Dummy Tag 3</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="from-group">
                                        <div class="form-group">
                                            <label for="customer_id">Customer <span class="text-danger">*</span></label>
                                            <select class="form-control" name="customer_id" id="customer_id" required>
                                                @foreach($customers as $customer)
                                                    <option {{ $sale->customer_id == $customer->id ? 'selected' : '' }} value="{{ $customer->id }}" data-email="{{ $customer->customer_email }}" data-address="{{ $customer->billing_address }}">{{ $customer->customer_name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="customer_email">Email Pelanggan <span class="text-danger">*</span></label>
                                            <input type="email" class="form-control" name="customer_email" id="customer_email"  required value="{{ $sale->customer_email }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="paying_bill_address">Alamat Penagihan <span class="text-danger">*</span></label>
                                            <textarea class="form-control" name="paying_bill_address" id="paying_bill_address"  required>{{ $sale->paying_bill_address }}</textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="from-group">
                                        <div class="form-group">
                                            <label for="date">Tanggal Transaksi <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="date" required value="{{ $sale->date }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="date">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="due_date" required value="{{ $sale->due_date }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="term_of_payment">Syarat Pembayaran<span class="text-danger">*</span></label>
                                            <select class="form-control" name="term_of_payment" id="term_of_payment" required>
                                                <option value="Cash">Cash</option>
                                                <option value="Credit">Credit</option>
                                                <option value="Net 30">Net 30</option>
                                                <option value="Net 60">Net 60</option>
                                                <option value="Net 90">Net 90</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <livewire:search-product/>
                            <livewire:product-cart :cartInstance="'sale'" :data="$sale"/>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="status">Status <span class="text-danger">*</span></label>
                                        <select class="form-control" name="status" id="status" required>
                                            <option {{ $sale->status == 'Pending' ? 'selected' : '' }} value="Pending">Pending</option>
                                            <option {{ $sale->status == 'Shipped' ? 'selected' : '' }} value="Shipped">Shipped</option>
                                            <option {{ $sale->status == 'Completed' ? 'selected' : '' }} value="Completed">Completed</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="from-group">
                                        <div class="form-group">
                                            <label for="payment_method">Payment Method <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="payment_method" required value="{{ $sale->payment_method }}" readonly>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="paid_amount">Amount Received <span class="text-danger">*</span></label>
                                        <input id="paid_amount" type="text" class="form-control" name="paid_amount" required value="{{ $sale->paid_amount }}" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="note">Note (If Needed)</label>
                                <textarea name="note" id="note" rows="5" class="form-control">{{ $sale->note }}</textarea>
                            </div>

                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    Update Sale <i class="bi bi-check"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(document).ready(function () {
            $('#paid_amount').maskMoney({
                prefix:'{{ settings()->currency->symbol }}',
                thousands:'{{ settings()->currency->thousand_separator }}',
                decimal:'{{ settings()->currency->decimal_separator }}',
                allowZero: true,
            });

            $('#paid_amount').maskMoney('mask');

            $('#sale-form').submit(function () {
                var paid_amount = $('#paid_amount').maskMoney('unmasked')[0];
                $('#paid_amount').val(paid_amount);
            });
            $('#customer_id').change(function () {
                var selectedOption = $(this).find('option:selected');
                var email = selectedOption.data('email');
                var address = selectedOption.data('address');

                $('#customer_email').val(email);
                $('#paying_bill_address').val(address);
            });
        });
    </script>
@endpush
