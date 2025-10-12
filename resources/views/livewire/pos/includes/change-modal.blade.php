<div class="modal fade" id="posChangeModal" tabindex="-1" role="dialog" aria-labelledby="posChangeModalLabel"
     aria-hidden="true" wire:ignore.self>
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posChangeModalLabel">Informasi Kembalian</h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center">
                @php
                    $descriptor = strtoupper($changeModalDescriptor ?? ($changeDescriptor ?? 'Kembalian'));
                    $modalChangeValue = $changeModalAmount ?? ($formattedChangeDue ?? '0');
                    $changeIsNegative = ($changeDue ?? 0) < 0;
                    $textClass = $changeModalHasPositiveChange ? 'text-success' : ($changeIsNegative ? 'text-danger' : 'text-primary');
                @endphp
                <p class="h4 font-weight-bold mb-2 {{ $textClass }}">
                    {{ $descriptor }} {{ $modalChangeValue }}
                </p>
                <p class="h5 text-muted mb-0">JANGAN LUPA UCAPKAN TERIMA KASIH!!</p>
                @if($changeIsNegative)
                    <p class="mt-3 text-danger mb-0">Sisa pembayaran masih diperlukan.</p>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
