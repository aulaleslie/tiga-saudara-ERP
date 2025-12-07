@props(['label', 'name', 'options' => [], 'selected' => null, 'required' => false, 'addCategoryButton' => false, 'placeholder' => 'Pilih', 'disabled' => false, 'quickAddButton' => null])

<div class="form-group">
    <label for="{{ $name }}">{{ $label }} @if($required)<span class="text-danger">*</span>@endif</label>
    <div class="input-group">
        <select class="form-control @error($name) is-invalid @enderror"
                name="{{ $name }}" id="{{ $name }}"
                @if($required) required @endif
                @if($disabled) disabled @endif>
            <option value="">{{ $placeholder }} {{ $label }}</option>
            @foreach($options as $key => $value)
                <option value="{{ $key }}" @if((string)$key === (string)old($name, $selected)) selected @endif>
                    {{ $value }}
                </option>
            @endforeach
        </select>
        @if($quickAddButton)
            {!! $quickAddButton !!}
        @endif
    </div>

    @error($name)
    <span class="invalid-feedback d-block" role="alert"><strong>{{ $message }}</strong></span>
    @enderror
</div>
