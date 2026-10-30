@php
    $resolvedNoteText = isset($noteText) ? (string) $noteText : '';
    $hasEligibleNote = trim($resolvedNoteText) !== '';
@endphp
@if($hasEligibleNote)
    <div class="receipt-note" id="pos-receipt-note" data-receipt-note @if(empty($showNote)) hidden @endif style="margin-top: 5px; border-top: 1px dashed #000; padding-top: 4px;">
        <div style="font-size: 11px; font-weight: 700; margin-bottom: 2px;">Catatan:</div>
        <div class="receipt-note-body" style="font-weight: 400; font-size: 10px; line-height: 13px; white-space: pre-wrap; word-break: break-word;">{{ $resolvedNoteText }}</div>
    </div>
@endif

