@extends('layouts.app')

@section('title', 'Update Stock Transfer')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfers</a></li>
        <li class="breadcrumb-item active">Update Transfer</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')

                        <!-- Full Form for Transfers, including Business Location and Product Table -->
                        <form id="transfer-form" action="{{ route('transfers.update', $transfer->id) }}" method="POST">
                            @csrf
                            @method('PUT')

                            <!-- Step 1: Display Origin Business, Origin Location, and Destination Location as read-only -->
                            <div class="form-row">
                                <!-- Origin Business (Read-Only) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="origin_business">Origin Business</label>
                                        <input type="text" class="form-control"
                                               value="{{ $transfer->originLocation->setting->company_name }}" readonly>
                                    </div>
                                </div>

                                <!-- Destination Business (Read-Only) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="destination_business">Destination Business</label>
                                        <input type="text" class="form-control"
                                               value="{{ $transfer->destinationLocation->setting->company_name }}"
                                               readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <!-- Origin Location (Read-Only) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="origin_location">Origin Location</label>
                                        <input type="text" class="form-control"
                                               value="{{ $transfer->originLocation->name }}" readonly>
                                    </div>
                                </div>

                                <!-- Destination Location (Read-Only) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="destination_location">Destination Location</label>
                                        <input type="text" class="form-control"
                                               value="{{ $transfer->destinationLocation->name }}" readonly>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2: Product Search and Table -->
                            <div class="row mt-4" id="product-section">
                                <div class="col-12">
                                    <livewire:transfer.search-product :locationId="$transfer->origin_location_id"/>
                                </div>

                                <div class="col-md-12 mt-4">
                                    <livewire:transfer.transfer-product-table
                                        :originLocationId="$transfer->origin_location_id"
                                        :destinationLocationId="$transfer->destination_location_id"
                                        :existingProducts="$transfer->products->toArray()"/>
                                </div>

                                <!-- Update button to submit the form -->
                                <div class="col-md-12 mt-4 text-right">
                                    <button type="submit" class="btn btn-primary">
                                        Update Transfer <i class="bi bi-check"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
