@extends('layouts.app')

@section('title', 'Laporan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item active">Laporan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <ul class="nav nav-pills card-header-pills">
                            @foreach($tabs as $tab)
                                <li class="nav-item mr-2">
                                    <a class="nav-link {{ $activeSlug === $tab['slug'] ? 'active' : '' }}" 
                                       href="{{ route('reports.index', ['tab' => $tab['slug']]) }}">
                                        <i class="{{ $tab['icon'] }} mr-1"></i> {{ $tab['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="card-body">
                        @if($activeTab)
                            <div class="row">
                                @foreach($activeTab['cards'] as $card)
                                    <div class="col-md-4 col-sm-6 mb-4">
                                        <a href="{{ route($card['route']) }}" class="text-decoration-none" style="color: inherit;">
                                            <div class="card h-100 shadow-sm hover-shadow border-primary">
                                                <div class="card-body text-center p-4">
                                                    <div class="mb-3 text-primary">
                                                        <i class="{{ $card['icon'] }}" style="font-size: 2.5rem;"></i>
                                                    </div>
                                                    <h5 class="card-title mb-0">{{ $card['label'] }}</h5>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_css')
<style>
    .hover-shadow {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .hover-shadow:hover {
        transform: translateY(-5px);
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
    }
    .hover-shadow .card-title {
        color: var(--primary) !important;
    }
</style>
@endpush
