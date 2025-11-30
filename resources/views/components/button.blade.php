@props([
    'type' => 'submit',
    'label' => '',
    'icon' => '',
    'id' => '',
    'processingText' => 'Processing…',
    'variant' => 'btn-primary',
])

<button
    type="{{ $type }}"
    @if($id) id="{{ $id }}" @endif
    {{ $attributes->merge(['class' => "btn {$variant} d-inline-flex align-items-center submit-lock-btn"]) }}
    data-processing-text="{{ $processingText }}"
    data-default-text="{{ trim($label) }}"
>
    <span class="spinner-border spinner-border-sm mr-2 d-none button-spinner" role="status" aria-hidden="true"></span>
    <span class="button-text">{{ $label }}</span>
    @if($icon)
        <i class="bi {{ $icon }} ml-1"></i>
    @endif
</button>
