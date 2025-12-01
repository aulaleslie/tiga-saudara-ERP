<div class="card shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div>
            <h5 class="mb-0">Status Sesi POS</h5>
        </div>
        <div>
            @if($session)
                <span class="badge badge-{{ $session->status === 'active' ? 'success' : ($session->status === 'paused' ? 'warning' : 'secondary') }}">
                    {{ strtoupper($session->status) }}
                </span>
            @else
                <span class="badge badge-secondary">TIDAK AKTIF</span>
            @endif
        </div>
    </div>
    <div class="card-body">
        @if(!$session)
            <form wire:submit.prevent="startSession" class="mb-0" id="startSessionForm">
                <div class="form-group">
                    <label class="font-weight-bold">Modal Kas Awal</label>
                    <input type="text" wire:ignore.self id="cashFloatInput" class="form-control" placeholder="Masukkan modal kas">
                    @error('cashFloat') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>

                <!-- Printer Selection -->
                <div class="form-group">
                    <label class="font-weight-bold">
                        <i class="bi bi-printer mr-1"></i> Konfigurasi Printer
                    </label>
                    <div class="input-group">
                        <input type="text" id="printerNameInput" class="form-control" placeholder="Nama printer (contoh: EPSON TM-T82)" readonly>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary" onclick="openPrinterSetupModal()">
                                <i class="bi bi-gear"></i> Pilih
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">Printer akan digunakan untuk mencetak struk. Klik "Pilih" untuk mengatur.</small>
                    <div id="printerError" class="text-danger small mt-1" style="display: none;">
                        Silakan pilih printer sebelum memulai sesi POS.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="startSessionBtn">Mulai Sesi POS</button>
            </form>
        @else
            <div class="row">
                <div class="col-md-6">
                    <h6 class="font-weight-bold">Informasi Sesi</h6>
                    <ul class="list-unstyled mb-4">
                        <li><strong>Kasir:</strong> {{ optional($session->cashier)->name }}</li>
                        <li><strong>Lokasi:</strong> {{ optional($session->location)->name ?? 'Tidak ditetapkan' }}</li>
                        <li><strong>Mulai:</strong> {{ optional($session->started_at)->format('d M Y H:i') }}</li>
                        @if($session->paused_at)
                            <li><strong>Jeda:</strong> {{ optional($session->paused_at)->format('d M Y H:i') }}</li>
                        @endif
                        @if($session->closed_at)
                            <li><strong>Tutup:</strong> {{ optional($session->closed_at)->format('d M Y H:i') }}</li>
                        @endif
                        <li><strong>Modal Kas:</strong> {{ number_format((float) $session->cash_float, 2, ',', '.') }}</li>
                        @if($session->expected_cash !== null)
                            <li><strong>Perkiraan Kas:</strong> {{ number_format((float) $session->expected_cash, 2, ',', '.') }}</li>
                        @endif
                        @if($session->discrepancy !== null)
                            <li><strong>Selisih:</strong> {{ number_format((float) $session->discrepancy, 2, ',', '.') }}</li>
                        @endif
                    </ul>
                </div>
                <div class="col-md-6">
                    @if($session->status === \App\Models\PosSession::STATUS_ACTIVE)
                        <form wire:submit.prevent="pauseSession" class="mb-4">
                            <h6 class="font-weight-bold">Jeda Sesi</h6>
                            <div class="form-group mb-2">
                                <label class="font-weight-bold">Kata Sandi</label>
                                <input type="password" wire:model.defer="pausePassword" class="form-control" autocomplete="current-password">
                                @error('pausePassword') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <button class="btn btn-warning" type="submit">Jeda</button>
                        </form>
                    @endif

                    @if($session->status === \App\Models\PosSession::STATUS_PAUSED)
                        <form wire:submit.prevent="resumeSession" class="mb-4">
                            <h6 class="font-weight-bold">Lanjutkan Sesi</h6>
                            <div class="form-group mb-2">
                                <label class="font-weight-bold">Kata Sandi</label>
                                <input type="password" wire:model.defer="resumePassword" class="form-control" autocomplete="current-password">
                                @error('resumePassword') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-success" type="submit">Lanjutkan</button>
                                <a href="{{ route('app.pos.index') }}" class="btn btn-outline-primary">
                                    <i class="bi bi-cart mr-1"></i> Ke POS
                                </a>
                            </div>
                        </form>
                    @endif

                    @if($session->status !== \App\Models\PosSession::STATUS_CLOSED)
                        <form wire:submit.prevent="closeSession" class="mb-0">
                            <h6 class="font-weight-bold">Tutup Sesi</h6>
                            <div class="form-group mb-2">
                                <label class="font-weight-bold">Perkiraan Kas (opsional)</label>
                                <input type="number" step="0.01" wire:model.lazy="expectedCash" class="form-control" placeholder="Perkiraan akhir">
                                @error('expectedCash') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-group mb-2">
                                <label class="font-weight-bold">Kas Terhitung</label>
                                <input type="number" step="0.01" min="0" wire:model.lazy="actualCash" class="form-control" placeholder="Jumlah kas fisik">
                                @error('actualCash') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-group mb-2">
                                <label class="font-weight-bold">Kata Sandi</label>
                                <input type="password" wire:model.defer="closePassword" class="form-control" autocomplete="current-password">
                                @error('closePassword') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>
                            <button class="btn btn-danger" type="submit">Tutup Sesi</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

@push('page_scripts')
    <script src="{{ asset('js/pos-printer.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initializeCashFloatInput();
            initializePrinterSelection();
        });

        function initializePrinterSelection() {
            const printerInput = document.getElementById('printerNameInput');
            if (!printerInput) return;

            // Load stored printer name
            const storedPrinter = window.PosPrinterManager.getStoredPrinter();
            if (storedPrinter) {
                printerInput.value = storedPrinter;
            } else {
                printerInput.value = '';
                printerInput.placeholder = 'Belum dikonfigurasi - Klik "Pilih"';
            }

            // Intercept form submission to check printer
            const form = document.getElementById('startSessionForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!window.PosPrinterManager.isPrinterConfigured()) {
                        e.preventDefault();
                        e.stopPropagation();
                        document.getElementById('printerError').style.display = 'block';
                        openPrinterSetupModal();
                        return false;
                    }
                    document.getElementById('printerError').style.display = 'none';
                });
            }
        }

        function openPrinterSetupModal() {
            const modal = document.getElementById('printerSetupModal');
            if (modal) {
                $('#printerSetupModal').modal('show');
                // Pre-fill with stored printer name
                const storedPrinter = window.PosPrinterManager.getStoredPrinter();
                const modalInput = document.getElementById('modalPrinterName');
                if (modalInput && storedPrinter) {
                    modalInput.value = storedPrinter;
                }
            }
        }

        function savePrinterSelection() {
            const printerName = document.getElementById('modalPrinterName').value.trim();
            if (!printerName) {
                alert('Silakan masukkan nama printer.');
                return;
            }

            window.PosPrinterManager.savePrinter(printerName);

            // Update display
            const printerInput = document.getElementById('printerNameInput');
            if (printerInput) {
                printerInput.value = printerName;
            }

            document.getElementById('printerError').style.display = 'none';
            $('#printerSetupModal').modal('hide');
        }

        function testPrinter() {
            const printerName = document.getElementById('modalPrinterName').value.trim();
            if (!printerName) {
                alert('Silakan masukkan nama printer terlebih dahulu.');
                return;
            }

            // Temporarily save for test
            window.PosPrinterManager.savePrinter(printerName);

            window.PosPrinterManager.testPrint()
                .then(() => {
                    alert('Test print berhasil dikirim! Periksa printer Anda.');
                })
                .catch((error) => {
                    alert('Gagal mengirim test print: ' + error.message);
                });
        }

        function clearPrinterSelection() {
            window.PosPrinterManager.clearPrinter();
            const printerInput = document.getElementById('printerNameInput');
            if (printerInput) {
                printerInput.value = '';
                printerInput.placeholder = 'Belum dikonfigurasi - Klik "Pilih"';
            }
            const modalInput = document.getElementById('modalPrinterName');
            if (modalInput) {
                modalInput.value = '';
            }
        }

        function initializeCashFloatInput() {
            const input = document.getElementById('cashFloatInput');
            if (!input) return;

            // Initialize with formatted currency on load
            setTimeout(() => {
                const initialValue = input.value || '';
                if (!initialValue || initialValue === '') {
                    input.value = 'Rp 0,00';
                }
            }, 100);

            // On focus: show plain number and select all
            input.addEventListener('focus', function() {
                const plain = parseFloat(parseCurrency(this.value)) || 0;
                this.value = plain.toString();
                this.select();
            });

            // On blur: format as currency and update Livewire
            input.addEventListener('blur', function() {
                const plain = parseFloat(parseCurrency(this.value)) || 0;
                this.value = formatCurrency(plain);

                // Update Livewire component
                const wireId = this.closest('[wire\\:id]')?.getAttribute('wire:id');
                if (wireId && typeof Livewire !== 'undefined') {
                    Livewire.find(wireId).set('cashFloat', plain);
                }
            });
        }

        function formatCurrency(value) {
            return 'Rp ' + new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value);
        }

        function parseCurrency(value) {
            if (typeof value !== 'string') return '0';
            return value.replace(/[^\d.,]/g, '').replace(/\./g, '').replace(',', '.');
        }
    </script>
@endpush

<!-- Printer Setup Modal -->
<div class="modal fade" id="printerSetupModal" tabindex="-1" role="dialog" aria-labelledby="printerSetupModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printerSetupModalLabel">
                    <i class="bi bi-printer mr-2"></i> Konfigurasi Printer
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle mr-1"></i>
                    <strong>Petunjuk:</strong> Masukkan nama printer thermal 80mm yang terhubung ke komputer ini.
                    Pastikan printer sudah diatur sebagai printer default di sistem operasi jika menggunakan mode kiosk.
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Nama Printer</label>
                    <input type="text" id="modalPrinterName" class="form-control" placeholder="Contoh: EPSON TM-T82, Xprinter XP-58">
                    <small class="text-muted">
                        Masukkan nama printer sesuai yang tertera di sistem. Untuk mode kiosk Chrome, ini hanya sebagai referensi.
                    </small>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Tips Pengaturan:</label>
                    <ul class="small text-muted mb-0">
                        <li>Pastikan printer thermal 80mm sudah terpasang dan terdeteksi di komputer.</li>
                        <li>Untuk Chrome kiosk mode, atur printer sebagai default di sistem operasi.</li>
                        <li>Gunakan tombol "Test Print" untuk memastikan printer berfungsi.</li>
                        <li>Pengaturan printer disimpan per perangkat (browser).</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger" onclick="clearPrinterSelection()">
                    <i class="bi bi-trash mr-1"></i> Hapus
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="testPrinter()">
                    <i class="bi bi-printer mr-1"></i> Test Print
                </button>
                <button type="button" class="btn btn-primary" onclick="savePrinterSelection()">
                    <i class="bi bi-check-lg mr-1"></i> Simpan
                </button>
            </div>
        </div>
    </div>
</div>
