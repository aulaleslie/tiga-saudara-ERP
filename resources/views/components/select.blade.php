@props(['label', 'name', 'options' => [], 'selected' => null, 'required' => false, 'addCategoryButton' => false, 'placeholder' => 'Pilih'])

<div class="form-group">
    <label for="{{ $name }}">{{ $label }}
        @if($required)
            <span class="text-danger">*</span>
        @endif
    </label>
    <div class="input-group">
        <select class="form-control @error($name) is-invalid @enderror" name="{{ $name }}" id="{{ $name }}"
                @if($required) required @endif>
            <option value="0">{{ $placeholder }} {{ $label }}</option> <!-- Allow re-selection of the placeholder -->
            @foreach($options as $key => $value)
                <option value="{{ $key }}"
                        @if($key == old($name, $selected)) selected @endif>
                    {{ $value }}
                </option>
            @endforeach
        </select>
    </div>

    <!-- Error Message -->
    @error($name)
    <span class="invalid-feedback d-block" role="alert">
            <strong>{{ $message }}</strong>
        </span>
    @enderror
</div>
