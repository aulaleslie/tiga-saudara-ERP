@extends('layouts.app')

@section('title', 'Jurnal')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/select/1.3.3/css/select.dataTables.min.css">
@endsection

@section('content')
    <div class="container">
        <h1>Jurnal</h1>
        <a href="{{ route('journals.create') }}" class="btn btn-primary mb-3">Buat Jurnal</a>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <table class="table table-bordered">
            <thead>
            <tr>
                <th>Tanggal Transaksi</th>
                <th>Deskripsi</th>
                <th># Jumlah Item</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            @forelse($journals as $journal)
                <tr>
                    <td>{{ $journal->transaction_date }}</td>
                    <td>{{ $journal->description }}</td>
                    <td>{{ $journal->items->count() }}</td>
                    <td>
                        <a href="{{ route('journals.show', $journal->id) }}" class="btn btn-info btn-sm">Lihat</a>
                        <a href="{{ route('journals.edit', $journal->id) }}" class="btn btn-warning btn-sm">Ubah</a>
                        <form action="{{ route('journals.destroy', $journal->id) }}" method="POST" style="display:inline-block;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Apakah anda yakin akan menghapus jurnal ini?');">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Tidak ada jurnal ditemukan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        {{ $journals->links() }}
    </div>
@endsection
