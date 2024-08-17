@props(['label', 'name', 'type' => 'text', 'value' => '', 'required' => false])

<div class="form-group">
    <label for="{{ $name }}">{{ $label }}
        @if($required)
            <span class="text-danger">*</span>
        @endif
    </label>

    <input type="{{ $type }}"
           class="form-control @error($name) is-invalid @enderror"
           name="{{ $name }}"
           id="{{ $name }}"
           value="{{ old($name, $value) }}"
           @if($required) required @endif>

    @error($name)
    <span class="invalid-feedback" role="alert">
            <strong>{{ $message }}</strong>
        </span>
    @enderror
</div>
