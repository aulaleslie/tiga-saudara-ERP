@extends('layouts.app')
@section('title','Import Batches')

@section('content')
    <div class="container-fluid">
        <h4 class="mb-3">Daftar Batch Upload Produk</h4>
        <table class="table table-sm table-striped">
            <thead>
            <tr>
                <th>ID</th><th>Dibuat Oleh</th><th>Lokasi</th><th>Status</th>
                <th>Progress</th><th>Total</th><th>OK</th><th>Error</th><th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            @foreach($batches as $b)
                <tr>
                    <td>#{{ $b->id }}</td>
                    <td>{{ optional($b->user)->name ?? '-' }}</td>
                    <td>{{ optional($b->location)->name ?? '-' }}</td>
                    <td><span class="badge bg-secondary">{{ $b->status }}</span></td>
                    <td>{{ $b->progress }}%</td>
                    <td>{{ $b->total_rows }}</td>
                    <td>{{ $b->success_rows }}</td>
                    <td>{{ $b->error_rows }}</td>
                    <td>
                        <a href="{{ route('products.imports.show',$b) }}" class="btn btn-sm btn-outline-primary">Detail</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{ $batches->links() }}
    </div>
@endsection
