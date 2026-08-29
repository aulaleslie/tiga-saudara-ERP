{{--
    AJAX-backed Select2 filter control.

    Renders only the currently selected option (label resolved server-side by the
    caller), so no full Supplier/Product collection is ever pushed into the view.

    @param string      $name         form field name
    @param string      $url          search endpoint
    @param string|null $selectedId   currently selected id (survives reloads/validation)
    @param string|null $selectedText label for the selected id
    @param string      $placeholder
    @param array       $dependsOn    optional map of extra query params: param => css selector
    @param int         $minInput     minimum characters before searching (0 = open on focus)
--}}
@php
    $selectId = $selectId ?? ('cs-' . $name . '-' . uniqid());
    $dependsOn = $dependsOn ?? [];
    $minInput = $minInput ?? 0;
    $required = $required ?? false;
@endphp

<select name="{{ $name }}"
        id="{{ $selectId }}"
        class="form-control consignment-ajax-select"
        data-url="{{ $url }}"
        data-placeholder="{{ $placeholder }}"
        data-min-input="{{ $minInput }}"
        data-depends="{{ json_encode($dependsOn) }}"
        @if($required) required @endif>
    <option value="">{{ $placeholder }}</option>
    @if(!empty($selectedId) && !empty($selectedText))
        <option value="{{ $selectedId }}" selected>{{ $selectedText }}</option>
    @endif
</select>
