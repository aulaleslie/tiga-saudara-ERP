<div>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>Pada Unit</th>
                <th>Faktor Konversi</th>
                <th>Barcode</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            @foreach($conversions as $index => $conversion)
                <tr>
                    <td>
                        <select name="conversions[{{ $index }}][unit_id]"
                                class="form-control {{ isset($errors['conversions.' . $index . '.unit_id']) ? 'is-invalid' : '' }}">
                            <option value="">Pilih Unit</option>
                            @foreach($units as $id => $name)
                                <option value="{{ $id }}" {{ $conversion['unit_id'] == $id ? 'selected' : '' }}>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                        @if(isset($errors['conversions.' . $index . '.unit_id']))
                            <span class="invalid-feedback"
                                  role="alert"><strong>{{ $errors['conversions.' . $index . '.unit_id'][0] }}</strong></span>
                        @endif
                    </td>
                    <td>
                        <input type="number" name="conversions[{{ $index }}][conversion_factor]"
                               class="form-control {{ isset($errors['conversions.' . $index . '.conversion_factor']) ? 'is-invalid' : '' }}"
                               step="0.0001"
                               value="{{ $conversion['conversion_factor'] }}">
                        @if(isset($errors['conversions.' . $index . '.conversion_factor']))
                            <span class="invalid-feedback"
                                  role="alert"><strong>{{ $errors['conversions.' . $index . '.conversion_factor'][0] }}</strong></span>
                        @endif
                    </td>
                    <td>
                        <input type="text" name="conversions[{{ $index }}][barcode]"
                               class="form-control {{ isset($errors['conversions.' . $index . '.barcode']) ? 'is-invalid' : '' }}"
                               value="{{ $conversion['barcode'] }}">
                        @if(isset($errors['conversions.' . $index . '.barcode']))
                            <span class="invalid-feedback"
                                  role="alert"><strong>{{ $errors['conversions.' . $index . '.barcode'][0] }}</strong></span>
                        @endif
                    </td>
                    <td>
                        <button type="button" class="btn btn-danger" wire:click="removeConversionRow({{ $index }})">
                            Hapus
                        </button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <button type="button" class="btn btn-primary" wire:click="addConversionRow">Tambah Konversi</button>
</div>
