<tr>
    <td>
        <select class="form-control" name="conversions[from_unit_id][]">
            @foreach($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->name }} | {{ $unit->short_name }}</option>
            @endforeach
        </select>
    </td>
    <td>
        <select class="form-control" name="conversions[to_unit_id][]">
            @foreach($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->name }} | {{ $unit->short_name }}</option>
            @endforeach
        </select>
    </td>
    <td>
        <input type="number" class="form-control" name="conversions[factor][]" step="0.0001" required>
    </td>
    <td>
        <button type="button" class="btn btn-danger remove_conversion">Remove</button>
    </td>
</tr>
