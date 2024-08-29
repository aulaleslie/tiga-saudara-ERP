@props(['label', 'name', 'type' => 'text', 'value' => '', 'required' => false, 'wireModel' => null, 'disabled' => null])

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
           @if($wireModel) wire:model="{{ $wireModel }}" @else value="{{ old($name, $value) }}" @endif
           @if($required) required @endif
           @if($disabled) disabled @endif>

    @error($name)
    <span class="invalid-feedback" role="alert">
            <strong>{{ $message }}</strong>
        </span>
    @enderror
</div>
