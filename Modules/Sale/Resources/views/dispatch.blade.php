@extends('layouts.app')

@section('title', 'Buat Pengeluaran')

@section('content')
    <div class="container">
        {{-- Komponen Header --}}
{{--        @livewire('sale.dispatch-sale-header', ['sale' => $sale, 'locations' => $locations])--}}

        {{-- Tampilkan error validasi --}}
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('sales.storeDispatch', $sale->id) }}" method="POST">
            @csrf
            <livewire:sale.dispatch-sale-header :sale="$sale"/>

            <div class="card p-3">
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="dispatch_date">Tanggal Pengiriman</label>
                            <input type="date" class="form-control" name="dispatch_date" required value="{{ now()->format('Y-m-d') }}">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Komponen Tabel --}}
{{--            @livewire('sale.dispatch-sale-table', ['sale' => $sale, 'aggregatedProducts' => $aggregatedProducts])--}}
            <livewire:sale.dispatch-sale-table :sale="$sale" :aggregatedProducts="$aggregatedProducts" :locations="$locations"/>

            <button type="submit" class="btn btn-success mt-3">Kirim</button>
        </form>
    </div>
@endsection
