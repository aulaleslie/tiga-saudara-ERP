<div class="modal fade" id="posChangeModal" tabindex="-1" role="dialog" aria-labelledby="posChangeModalLabel"
     aria-hidden="true" wire:ignore.self>
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posChangeModalLabel">Informasi Kembalian</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center">
                @if($changeModalHasPositiveChange)
                    <p class="h4 font-weight-bold text-success mb-0">
                        {{ 'KEMBALIAN Rp. ' . $changeModalAmount . ' . JANGAN LUPA UCAPKAN TERIMA KASIH!!' }}
                    </p>
                @else
                    <p class="h4 font-weight-bold text-primary mb-0">
                        JANGAN LUPA UCAPKAN TERIMA KASIH
                    </p>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
