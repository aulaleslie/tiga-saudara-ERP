<div>
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>ke Unit</th>
                <th>Faktor Konversi</th>
                <th>Barcode</th>
                <th>Harga</th>
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
                        {{-- numeric copy → this is what your form really submits --}}
                        <input type="hidden"
                               name="conversions[{{ $index }}][price]"
                               wire:model="conversions.{{ $index }}.price"
                               value="{{ $conversion['price'] }}"/>

                        {{-- pretty input --}}
                        <input type="text"
                               class="form-control {{ isset($errors['conversions.' . $index . '.price']) ? 'is-invalid' : '' }}"
                               placeholder="0,00"

                               wire:model="displayPrices.{{ $index }}"      {{-- ⬅ removed “.lazy” --}}
                               wire:focus="showRawPrice({{ $index }})"
                               wire:blur="syncPrice({{ $index }})"          {{-- ⬅ no extra arg needed --}}
                        />
                        @if(isset($errors['conversions.' . $index . '.price']))
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $errors['conversions.' . $index . '.price'][0] }}</strong>
                            </span>
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
    <button type="button" class="btn btn-primary"
            wire:click="addConversionRow"
        @disabled($locked)>
        Tambahkan
    </button>
</div>
