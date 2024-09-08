@props(['type' => 'submit', 'label' => '', 'icon' => '', 'id' => ''])

<button type="{{ $type }}" @if($id) id="{{ $id }}" @endif class="btn btn-primary">
    {{ $label }}
    @if($icon)
        <i class="bi {{ $icon }}"></i>
    @endif
</button>
