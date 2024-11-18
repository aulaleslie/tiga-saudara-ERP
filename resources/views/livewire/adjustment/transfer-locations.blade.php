<div>
    <div class="form-row">
        <div class="col-lg-6">
            <div class="form-group">
                <label for="current_business">Bisnis Saat Ini</label>
                <input type="text" class="form-control" value="{{ $currentSetting->company_name }}" readonly>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="form-group">
                <label for="destination_setting">Bisnis Tujuan</label>
                <select wire:model="destinationSettingId" class="form-control" {{ $formDisabled ? 'disabled' : '' }}>
                    <option value="">Pilih Bisnis Tujuan</option>
                    @foreach($settings as $setting)
                        <option value="{{ $setting->id }}">{{ $setting->company_name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="form-row">
        <div class="col-lg-6">
            <div class="form-group">
                <label for="location_origin">Lokasi Asal</label>
                <select wire:model="locationOriginId" name="location_origin" class="form-control" {{ $formDisabled ? 'disabled' : '' }}>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="form-group">
                <label for="location_destination">Lokasi Tujuan</label>
                <select wire:model="locationDestinationId" name="location_destination" class="form-control" {{ $formDisabled ? 'disabled' : '' }}>
                    @foreach($destinationLocations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>
