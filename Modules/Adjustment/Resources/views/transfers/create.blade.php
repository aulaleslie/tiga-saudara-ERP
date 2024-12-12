@extends('layouts.app')

@section('title', 'Transfer Stock')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfers</a></li>
        <li class="breadcrumb-item active">Create Transfer</li>
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
                        <form id="transfer-form" action="{{ route('transfers.store') }}" method="POST">
                            @csrf

                            <!-- Step 1: Select Destination Business, Origin Location, and Destination Location -->
                            <div class="form-row">
                                <!-- Origin Business (Disabled) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="origin_business">Origin Business</label>
                                        <input type="text" class="form-control" value="{{ $currentSetting->company_name }}" readonly>
                                    </div>
                                </div>

                                <!-- Destination Business -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="destination_business">Destination Business</label>
                                        <select name="destination_business" id="destination_business" class="form-control" required>
                                            <option value="" disabled selected>Select Destination Business</option>
                                            @foreach($settings as $setting)
                                                <option value="{{ $setting->id }}">{{ $setting->company_name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <!-- Origin Location (Initially Disabled) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="origin_location">Origin Location</label>
                                        <select name="origin_location" id="origin_location" class="form-control" disabled required>
                                            <option value="" disabled selected>Select Origin Location</option>
                                            @foreach($locations as $location)
                                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <!-- Destination Location (Initially Disabled) -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="destination_location">Destination Location</label>
                                        <select name="destination_location" id="destination_location" class="form-control" disabled required>
                                            <option value="" disabled selected>Select Destination Location</option>
                                            <!-- Destination location options will be dynamically updated based on selected business -->
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Confirm button to finalize selections and enable product search -->
                            <button type="button" id="confirm-selections" class="btn btn-primary" disabled>
                                Confirm Selections
                            </button>

                            <!-- Step 2: Product Search and Table (Initially Hidden) -->
                            <div class="row mt-4 d-none" id="product-section">
                                <div class="col-12">
                                    <livewire:transfer.search-product :locationId="old('origin_location')"/>
                                </div>

                                <div class="col-md-12 mt-4">
                                    <livewire:transfer.transfer-product-table :originLocationId="old('origin_location')" :destinationLocationId="old('destination_location')"/>
                                </div>

                                <!-- Simpan button to submit the form -->
                                <div class="col-md-12 mt-4 text-right">
                                    @canany('tfstock.create')
                                    <button type="submit" class="btn btn-primary">
                                        Simpan <i class="bi bi-check"></i>
                                    </button>
                                    @endcanany
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('third_party_scripts')
    <script>
        const destinationBusinessSelect = document.getElementById('destination_business');
        const originLocationSelect = document.getElementById('origin_location');
        const destinationLocationSelect = document.getElementById('destination_location');
        const confirmButton = document.getElementById('confirm-selections');

        const allDestinationLocations = @json($destinationLocations);

        // Initially disable the origin and destination location dropdowns
        originLocationSelect.setAttribute('disabled', 'disabled');
        destinationLocationSelect.setAttribute('disabled', 'disabled');

        // Enable the origin location dropdown when a destination business is selected
        destinationBusinessSelect.addEventListener('change', function () {
            const selectedBusinessId = this.value;

            if (selectedBusinessId) {
                // Enable the origin location dropdown
                originLocationSelect.removeAttribute('disabled');
            }
        });

        // Enable the destination location dropdown when an origin location is selected and exclude the selected origin location
        originLocationSelect.addEventListener('change', function () {
            const selectedOriginLocationId = this.value;
            const selectedBusinessId = destinationBusinessSelect.value;

            if (selectedOriginLocationId) {
                // Filter destination locations to exclude the selected origin location
                const filteredLocations = allDestinationLocations.filter(location => location.setting_id == selectedBusinessId && location.id != selectedOriginLocationId);

                console.log("allDestination", JSON.stringify(allDestinationLocations))
                console.log("filtered", JSON.stringify(filteredLocations))
                console.log("selectedOriginLocationId", JSON.stringify(selectedOriginLocationId))
                console.log("selectedBusinessId", JSON.stringify(selectedBusinessId))
                // Clear and repopulate the destination location select dropdown
                destinationLocationSelect.innerHTML = '<option value="" disabled selected>Select Destination Location</option>';
                filteredLocations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location.id;
                    option.textContent = location.name;
                    destinationLocationSelect.appendChild(option);
                });

                // Enable the destination location dropdown
                destinationLocationSelect.removeAttribute('disabled');
            }
        });

        // Enable the confirm button if both origin and destination locations are selected
        [originLocationSelect, destinationLocationSelect].forEach(selectElement => {
            selectElement.addEventListener('change', function () {
                if (originLocationSelect.value && destinationLocationSelect.value) {
                    confirmButton.removeAttribute('disabled');
                } else {
                    confirmButton.setAttribute('disabled', 'disabled');
                }
            });
        });

        // Confirm button click event
        confirmButton.addEventListener('click', function () {
            const originLocation = originLocationSelect.value;
            const destinationLocation = destinationLocationSelect.value;

            if (originLocation && destinationLocation) {
                // Enable product search and table section
                document.getElementById('product-section').classList.remove('d-none');

                // Trigger Livewire update with the selected locations
                Livewire.dispatch('locationsConfirmed', {
                    originLocationId: originLocation,
                    destinationLocationId: destinationLocation
                });

                // Disable the confirm selections button to prevent further changes
                confirmButton.setAttribute('disabled', 'disabled');
            } else {
                alert('Please select all required fields.');
            }
        });
    </script>
@endsection
