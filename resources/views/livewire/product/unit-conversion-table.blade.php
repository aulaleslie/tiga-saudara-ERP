<div>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th>To Unit</th>
            <th>Conversion Factor</th>
            <th>Barcode</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        @foreach($conversions as $index => $conversion)
            <tr>
                <td>
                    <select name="conversions[{{ $index }}][unit_id]"
                            class="form-control @error('conversions.' . $index . '.unit_id') is-invalid @enderror">
                        <option value="">Select Unit</option>
                        @foreach($units as $id => $name)
                            <option value="{{ $id }}" {{ $conversion['unit_id'] == $id ? 'selected' : '' }}>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('conversions.' . $index . '.unit_id')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                    @enderror
                </td>
                <td>
                    <input type="number" name="conversions[{{ $index }}][conversion_factor]"
                           class="form-control @error('conversions.' . $index . '.conversion_factor') is-invalid @enderror"
                           step="0.0001"
                           value="{{ $conversion['conversion_factor'] }}">
                    @error('conversions.' . $index . '.conversion_factor')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                    @enderror
                </td>
                <td>
                    <input type="text" name="conversions[{{ $index }}][barcode]"
                           class="form-control @error('conversions.' . $index . '.barcode') is-invalid @enderror"
                           value="{{ $conversion['barcode'] }}">
                    @error('conversions.' . $index . '.barcode')
                    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                    @enderror
                </td>
                <td>
                    <button type="button" class="btn btn-danger" wire:click="removeConversionRow({{ $index }})">Remove
                    </button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <button type="button" class="btn btn-primary" wire:click="addConversionRow">Add Conversion</button>
</div>
