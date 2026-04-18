    <div id="pos-camera-scanner-modal" class="modal fade pos-camera-scanner-modal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg modal-dialog-centered pos-camera-scanner-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="pos-camera-scanner-title-wrap">
                        <h5 class="modal-title pos-camera-scanner-title">Pindai Kamera</h5>
                        <p class="pos-camera-scanner-subtitle">Sesi tetap terbuka sampai kasir menutup scanner.</p>
                    </div>
                    <button id="pos-camera-scanner-close" type="button" class="close pos-camera-scanner-close" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="pos-camera-scanner-body" class="pos-camera-scanner-body">
                        <div class="pos-camera-preview-shell">
                            <video id="pos-camera-video" class="pos-camera-video"></video>
                            <div id="pos-html5qrcode-container" class="pos-camera-video" style="display:none;"></div>
                            <div class="pos-camera-scan-lane" aria-hidden="true"></div>
                            <div class="pos-camera-guide-copy" aria-hidden="true">Posisikan barcode di dalam jalur scan untuk pemindaian beruntun.</div>
                        </div>
                        <div id="pos-camera-scanner-session-status" class="pos-camera-session-status" data-status-tone="ready">
                            <div id="pos-camera-scanner-status-chip" class="pos-camera-status-chip">Siap</div>
                            <p id="pos-camera-scanner-status" class="pos-camera-session-headline">Menyiapkan kamera scanner.</p>
                            <p id="pos-camera-scanner-detail" class="pos-camera-session-detail">Setelah kamera aktif, arahkan barcode ke jalur scan dan lanjutkan pindai item berikutnya tanpa menutup modal.</p>
                            <div class="pos-camera-session-meta">
                                <span>Scanner virtual aktif</span>
                                <span>Input scan tetap sinkron</span>
                                <span>Tutup saat sesi selesai</span>
                            </div>
                            <button id="pos-camera-scanner-dismiss" type="button" class="btn btn-primary d-none" style="margin-top: 0.5rem;">Lanjutkan Scan</button>
                        </div>
                        <div id="pos-camera-scanner-debug" class="pos-scanner-debug-panel" aria-hidden="true"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="pos-camera-scanner-retry" type="button" class="btn btn-secondary d-none">Coba Lagi</button>
                    <button id="pos-camera-scanner-cancel" type="button" class="btn btn-outline-light">Selesai Scan</button>
                </div>
            </div>
        </div>
    </div>
