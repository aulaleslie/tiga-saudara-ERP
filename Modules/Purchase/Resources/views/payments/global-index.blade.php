@extends('layouts.app')

@section('title', 'Pembayaran Pembelian Global')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Pembayaran Pembelian Global</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        {{-- No create or import buttons for global payment view --}}

                        <livewire:purchase.purchase-summary-cards
                            :globalMode="true"
                            :globalBusinessFilters="request()->query('globalBusinessFilters', []) ? (is_array(request()->query('globalBusinessFilters')) ? request()->query('globalBusinessFilters') : array_filter([request()->query('globalBusinessFilters')])) : []"
                            :documentDateFrom="request('documentDateFrom')"
                            :documentDateTo="request('documentDateTo')"
                            :dueDateFrom="request('dueDateFrom')"
                            :dueDateTo="request('dueDateTo')"
                            :selectedCardFilter="request('selectedCardFilter')"
                        />

                        <div class="table-responsive" style="min-height: 300px;">
                            <livewire:purchase.purchase-table :globalMode="true" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
