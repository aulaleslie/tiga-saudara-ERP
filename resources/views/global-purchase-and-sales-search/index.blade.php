@extends('layouts.app')

@section('title', 'Pencarian Pembelian dan Penjualan Global')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Breadcrumb -->
            <div class="card">
                <div class="card-body">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item">
                                <a href="{{ route('home') }}">
                                    <i class="fas fa-home"></i> Home
                                </a>
                            </li>
                            <li class="breadcrumb-item active">
                                <i class="fas fa-search"></i> Pencarian Global
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    </div>

    <!-- Livewire Component -->
    <div class="row">
        <div class="col-12">
            @livewire('global-purchase-and-sales-search')
        </div>
    </div>
</div>
@endsection

@section('styles')
<style>
/* Additional custom styles can be added here */
.card-header .card-tools {
    margin-top: 0;
}

.table-responsive {
    max-height: 600px;
    overflow-y: auto;
}

@media (max-width: 768px) {
    .global-search-container .input-group {
        flex-direction: column;
    }

    .global-search-container .input-group .input-group-append {
        margin-top: 0.5rem;
        align-self: stretch;
    }

    .global-search-container .input-group .input-group-append .btn {
        width: 100%;
    }
}
</style>
@endsection

@section('scripts')
<script>
// Additional JavaScript can be added here if needed
</script>
@endsection