@extends('layouts.app')

@section('title', 'Detail Retur POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.index') }}">POS</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.returns.index') }}">Retur POS</a></li>
        <li class="breadcrumb-item active">Detail Retur</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Retur: {{ $return->reference }}</strong>
                            <span class="badge badge-info ml-2">{{ strtoupper($return->status) }}</span>
                        </div>
                        <div>
                            @can('pos.returns.edit')
                                @if($return->status == \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL)
                                    <a href="{{ route('pos.returns.edit', $return->id) }}" class="btn btn-warning btn-sm">Edit</a>
                                @endif
                            @endcan
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-4">
                                <h6 class="mb-3">Informasi Retur:</h6>
                                <div>Kode: <strong>{{ $return->reference }}</strong></div>
                                <div>Tanggal: {{ $return->created_at->format('d M Y H:i') }}</div>
                                <div>Opsi: {{ $return->return_option == 'cash_return' ? 'Kembali Uang' : 'Ganti Produk' }}</div>
                            </div>
                            <div class="col-sm-4">
                                <h6 class="mb-3">Informasi Transaksi:</h6>
                                <div>Struk: <strong>{{ $return->receipt_number }}</strong></div>
                                <div>Transaksi: {{ $return->transaction_code }}</div>
                                <div>Pelanggan: {{ $return->customer_name }}</div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center">Jumlah</th>
                                        <th class="text-right">Harga Satuan</th>
                                        <th class="text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($return->lines as $line)
                                        <tr>
                                            <td>
                                                <div>{{ $line->product_name }}</div>
                                                <small class="text-muted">{{ $line->product_code }}</small>
                                                @if(!empty($line->serial_number_ids))
                                                    <div class="mt-1 small">
                                                        SN: @foreach($line->serial_number_ids as $snId) <span class="badge badge-light border">SN-{{ $snId }}</span> @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-center">{{ (float)$line->quantity }}</td>
                                            <td class="text-right">{{ format_currency($line->unit_price) }}</td>
                                            <td class="text-right">{{ format_currency($line->line_total) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="3" class="text-right">Total:</th>
                                        <th class="text-right"><strong>{{ format_currency($return->total_amount) }}</strong></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
