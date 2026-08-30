@props([
    'note' => null,
    'rowId' => null,
])

@php
    $rawNote = (string) ($note ?? '');
    $hasNote = trim($rawNote) !== '';

    if ($hasNote) {
        $lines = preg_split('/\r\n|\r|\n/', $rawNote);
        $lineCount = count($lines);
        $charCount = mb_strlen($rawNote);

        $isExpandable = $charCount > 120 || $lineCount > 3;

        if ($isExpandable) {
            $previewText = mb_substr($rawNote, 0, 120);
            $previewLines = preg_split('/\r\n|\r|\n/', $previewText);
            if (count($previewLines) > 3) {
                $previewText = implode("\n", array_slice($previewLines, 0, 3));
            }
        } else {
            $previewText = $rawNote;
        }

        $baseId = $rowId ? (string) $rowId : 'doc-note-' . uniqid();
        $previewId = $baseId . '-preview';
        $fullId = $baseId . '-full';
    }
@endphp

@if ($hasNote)
    <div class="document-note-container text-muted small">
        @if ($isExpandable)
            <div x-data="{ expanded: false }">
                <div id="{{ $previewId }}" x-show="!expanded">
                    <span>{{ $previewText }}...</span>
                    <button type="button"
                            class="btn btn-link p-0 border-0 text-primary small align-baseline"
                            style="font-size: inherit; text-decoration: underline;"
                            @click="expanded = true"
                            :aria-expanded="expanded ? 'true' : 'false'"
                            aria-controls="{{ $previewId }} {{ $fullId }}">
                        Lihat selengkapnya
                    </button>
                </div>
                <div id="{{ $fullId }}" x-show="expanded" x-cloak>
                    <span>{{ $rawNote }}</span>
                    <button type="button"
                            class="btn btn-link p-0 border-0 text-primary small align-baseline"
                            style="font-size: inherit; text-decoration: underline;"
                            @click="expanded = false"
                            :aria-expanded="expanded ? 'true' : 'false'"
                            aria-controls="{{ $previewId }} {{ $fullId }}">
                        Tampilkan lebih sedikit
                    </button>
                </div>
            </div>
        @else
            <span>{{ $rawNote }}</span>
        @endif
    </div>
@else
    <span class="text-muted">-</span>
@endif
