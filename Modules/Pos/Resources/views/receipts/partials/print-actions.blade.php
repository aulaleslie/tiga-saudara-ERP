<div class="no-print">
    <button onclick="window.print()">Cetak Struk</button>
    <label class="receipt-note-toggle" for="pos-receipt-show-note-toggle">
        <input
            type="checkbox"
            id="pos-receipt-show-note-toggle"
            data-receipt-note-toggle
            data-default-checked="{{ !empty($showNote) ? '1' : '0' }}"
            autocomplete="off"
            @checked(!empty($showNote))
        >
        <span>Tampilkan Catatan Transaksi</span>
    </label>
</div>

