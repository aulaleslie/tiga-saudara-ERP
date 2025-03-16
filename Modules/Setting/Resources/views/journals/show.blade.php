@extends('layouts.app')

@section('title', 'Lihat Jurnal')

@section('content')
    <div class="container">
        <h1>Lihat Jurnal</h1>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Tanggal Transaksi: {{ $journal->transaction_date }}</h5>
                <p class="card-text">
                    <strong>Deskripsi: </strong>
                    {{ $journal->description ?? 'Tidak ada deskripsi.' }}
                </p>
            </div>
        </div>

        <h3>Item Jurnal</h3>
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>Akun</th>
                <th>Tipe</th>
                <th>Jumlah</th>
            </tr>
            </thead>
            <tbody>
            @forelse($journal->items as $item)
                <tr>
                    <td>
                        {{ $item->chartOfAccount->name }}<br>
                        <small>({{ $item->chartOfAccount->account_number }})</small>
                    </td>
                    <td>{{ ucfirst($item->type) }}</td>
                    <td>{{ number_format($item->amount, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Tidak ada item jurnal ditemukan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <a href="{{ route('journals.index') }}" class="btn btn-primary">Kembali ke Daftar Jurnal</a>
    </div>
@endsection
