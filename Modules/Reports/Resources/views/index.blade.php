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
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-white pt-4 pb-0 px-4 border-bottom">
                        <ul class="nav mekari-tabs">
                            @foreach($tabs as $tab)
                                <li class="nav-item mr-4">
                                    <a class="nav-link px-0 pb-3 pt-2 {{ $activeSlug === $tab['slug'] ? 'active font-weight-bold text-dark' : 'text-muted' }}" 
                                       href="{{ route('reports.index', ['tab' => $tab['slug']]) }}">
                                        <i class="{{ $tab['icon'] }} mr-1"></i> {{ $tab['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="card-body p-4">
                        @if($activeTab)
                            <div class="row">
                                @foreach($activeTab['cards'] as $card)
                                    <div class="col-md-4 col-sm-6 mb-4">
                                        <a href="{{ route($card['route']) }}" class="text-decoration-none card-link-wrapper" style="color: inherit;">
                                            <div class="card h-100 mekari-card">
                                                <div class="card-body p-4 d-flex flex-column">
                                                    <div class="d-flex align-items-center mb-3">
                                                        <i class="{{ $card['icon'] }} text-primary mr-3" style="font-size: 1.5rem;"></i>
                                                        <h6 class="card-title mb-0 font-weight-bold text-dark">{{ $card['label'] }}</h6>
                                                    </div>
                                                    <p class="card-text text-muted small mb-4 flex-grow-1">
                                                        {{ $card['description'] }}
                                                    </p>
                                                    <div class="mt-auto">
                                                        <span class="btn btn-outline-primary btn-sm rounded-pill px-3 mekari-btn">Lihat laporan</span>
                                                    </div>
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
    .mekari-tabs {
        border-bottom: none;
        margin-bottom: -1px;
    }
    .mekari-tabs .nav-link {
        border: none;
        border-bottom: 3px solid transparent;
        transition: all 0.2s ease;
        font-size: 0.95rem;
    }
    .mekari-tabs .nav-link:hover {
        color: #343a40 !important;
        border-bottom-color: #dee2e6;
    }
    .mekari-tabs .nav-link.active {
        color: var(--primary) !important;
        border-bottom-color: var(--primary);
    }
    
    .mekari-card {
        border: 1px solid #e9ecef;
        border-radius: 8px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        background-color: #fff;
    }
    .card-link-wrapper:hover .mekari-card {
        transform: translateY(-3px);
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.08)!important;
        border-color: var(--primary);
    }
    .card-link-wrapper:hover .mekari-btn {
        background-color: var(--primary);
        color: white;
    }
</style>
@endpush
