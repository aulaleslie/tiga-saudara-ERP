<div class="col-12">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Autentikasi Dua Faktor (2FA)</h5>
            @if (auth()->user()->hasTwoFactorEnabled())
                <span class="badge bg-success">
                    <i class="bi bi-shield-check"></i> Aktif sejak {{ auth()->user()->two_factor_confirmed_at->format('d M Y') }}
                </span>
            @else
                <span class="badge bg-warning">
                    <i class="bi bi-exclamation-circle"></i> Tidak Diaktifkan
                </span>
            @endif
        </div>
        <div class="card-body">
            @if (auth()->user()->hasTwoFactorEnabled())
                <!-- Active State -->
                <div id="twoFactorActive">
                    <div class="alert alert-success d-flex align-items-center" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <div>
                            Autentikasi dua faktor Anda aktif
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h6>Uji Kode Anda</h6>
                        <div class="input-group">
                            <input type="text" class="form-control" id="testCode" placeholder="Masukkan kode 6 digit" maxlength="6">
                            <button class="btn btn-outline-secondary" type="button" id="testCodeBtn">Uji</button>
                        </div>
                        <small class="text-muted d-block mt-2">Masukkan kode dari aplikasi autentikator Anda untuk memverifikasi bahwa semuanya berfungsi dengan baik.</small>
                        <div id="testCodeFeedback" class="mt-2"></div>
                    </div>

                    <div class="mt-4 pt-3 border-top">
                        <button class="btn btn-danger" id="disableBtn" type="button">
                            <i class="bi bi-trash"></i> Matikan 2FA
                        </button>
                    </div>
                </div>
            @else
                <!-- Not Set Up State -->
                <div id="twoFactorNotSetup">
                    <p class="text-muted">Lindungi akun Anda dengan menambahkan autentikasi dua faktor melalui aplikasi autentikator.</p>
                    <button class="btn btn-primary" id="setupBtn" type="button">
                        <i class="bi bi-shield-lock"></i> Aktifkan 2FA
                    </button>
                </div>

                <!-- Setup In Progress State (hidden initially) -->
                <div id="twoFactorSetupProgress" style="display: none;">
                    <div class="setup-steps">
                        <h6 class="mb-3">Langkah 1: Pindai Kode QR</h6>
                        <p class="text-muted">Gunakan aplikasi autentikator (Google Authenticator, Authy, Microsoft Authenticator, dll) dan pindai kode QR di bawah ini:</p>
                        
                        <div id="qrCodeContainer" class="text-center mb-4">
                            <!-- QR code will be rendered here -->
                        </div>

                        <div class="alert alert-info">
                            <strong>Atau masukkan kunci secara manual:</strong>
                            <div id="manualKeyContainer" class="mt-2">
                                <code id="manualKey" style="font-size: 16px; word-break: break-all;"></code>
                                <button class="btn btn-sm btn-outline-secondary ms-2" id="copyKeyBtn" type="button">
                                    <i class="bi bi-files"></i> Salin
                                </button>
                            </div>
                        </div>

                        <hr>

                        <h6 class="mb-3">Langkah 2: Masukkan Kode Verifikasi</h6>
                        <p class="text-muted">Masukkan kode 6 digit dari aplikasi autentikator Anda:</p>
                        
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="confirmCode" placeholder="000000" maxlength="6">
                            <button class="btn btn-outline-secondary" type="button" id="confirmCodeBtn">Konfirmasi</button>
                        </div>
                        <div id="confirmCodeFeedback" class="mt-2"></div>

                        <!-- Recovery Codes (shown after confirmation) -->
                        <div id="recoveryCodesSection" style="display: none;" class="mt-4">
                            <div class="alert alert-warning">
                                <h6 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Kode Pemulihan Penting</h6>
                                <p class="mb-0">Simpan kode pemulihan ini di tempat yang aman. Anda dapat menggunakannya untuk mengakses akun jika kehilangan perangkat autentikator Anda.</p>
                            </div>
                            <div id="recoveryCodesList" class="mb-3" style="background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; line-height: 1.8;">
                                <!-- Recovery codes will be displayed here -->
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" id="copyRecoveryCodesBtn" type="button">
                                <i class="bi bi-files"></i> Salin Semua Kode
                            </button>
                            <button class="btn btn-sm btn-primary" id="finishSetupBtn" type="button">
                                <i class="bi bi-check"></i> Selesai
                            </button>
                        </div>

                        <div class="mt-3">
                            <button class="btn btn-outline-secondary" id="cancelSetupBtn" type="button">Batal</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const setupBtn = document.getElementById('setupBtn');
    const cancelSetupBtn = document.getElementById('cancelSetupBtn');
    const confirmCodeBtn = document.getElementById('confirmCodeBtn');
    const testCodeBtn = document.getElementById('testCodeBtn');
    const disableBtn = document.getElementById('disableBtn');
    const copyKeyBtn = document.getElementById('copyKeyBtn');
    const copyRecoveryCodesBtn = document.getElementById('copyRecoveryCodesBtn');
    const finishSetupBtn = document.getElementById('finishSetupBtn');

    let currentSecret = null;

    // Setup button
    if (setupBtn) {
        setupBtn.addEventListener('click', async function() {
            try {
                const response = await fetch('{{ route("2fa.setup") }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();

                if (!response.ok) {
                    showFeedback('confirmCodeFeedback', 'Gagal memulai setup: ' + (data.message || 'Unknown error'), 'danger');
                    return;
                }

                currentSecret = data.secret;

                // Display QR code
                document.getElementById('qrCodeContainer').innerHTML = data.qr_code_svg;

                // Display manual key
                document.getElementById('manualKey').textContent = data.secret;

                // Show setup progress, hide not setup
                document.getElementById('twoFactorNotSetup').style.display = 'none';
                document.getElementById('twoFactorSetupProgress').style.display = 'block';
            } catch (error) {
                showFeedback('confirmCodeFeedback', 'Kesalahan jaringan: ' + error.message, 'danger');
            }
        });
    }

    // Cancel setup button
    if (cancelSetupBtn) {
        cancelSetupBtn.addEventListener('click', function() {
            document.getElementById('twoFactorSetupProgress').style.display = 'none';
            document.getElementById('twoFactorNotSetup').style.display = 'block';
            document.getElementById('recoveryCodesSection').style.display = 'none';
            document.getElementById('confirmCode').value = '';
            document.getElementById('confirmCodeFeedback').innerHTML = '';
            currentSecret = null;
        });
    }

    // Confirm code button
    if (confirmCodeBtn) {
        confirmCodeBtn.addEventListener('click', async function() {
            const code = document.getElementById('confirmCode').value.trim();

            if (code.length !== 6 || !/^\d+$/.test(code)) {
                showFeedback('confirmCodeFeedback', 'Masukkan kode 6 digit yang valid', 'danger');
                return;
            }

            try {
                const response = await fetch('{{ route("2fa.confirm") }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ code }),
                });

                const data = await response.json();

                if (!response.ok) {
                    showFeedback('confirmCodeFeedback', data.message || 'Kode tidak valid', 'danger');
                    return;
                }

                showFeedback('confirmCodeFeedback', 'Kode valid! Simpan kode pemulihan Anda.', 'success');

                // Display recovery codes
                const recoveryCodesList = document.getElementById('recoveryCodesList');
                recoveryCodesList.innerHTML = data.recovery_codes.join('<br>');
                document.getElementById('recoveryCodesSection').style.display = 'block';
            } catch (error) {
                showFeedback('confirmCodeFeedback', 'Kesalahan jaringan: ' + error.message, 'danger');
            }
        });
    }

    // Test code button
    if (testCodeBtn) {
        testCodeBtn.addEventListener('click', async function() {
            const code = document.getElementById('testCode').value.trim();

            if (code.length !== 6 || !/^\d+$/.test(code)) {
                showFeedback('testCodeFeedback', 'Masukkan kode 6 digit yang valid', 'danger');
                return;
            }

            try {
                const response = await fetch('{{ route("2fa.test") }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ code }),
                });

                const data = await response.json();

                if (!response.ok) {
                    showFeedback('testCodeFeedback', data.message || 'Kode tidak valid', 'danger');
                    return;
                }

                showFeedback('testCodeFeedback', 'Kode valid!', 'success');
                document.getElementById('testCode').value = '';
            } catch (error) {
                showFeedback('testCodeFeedback', 'Kesalahan jaringan: ' + error.message, 'danger');
            }
        });
    }

    // Disable button
    if (disableBtn) {
        disableBtn.addEventListener('click', async function() {
            if (!confirm('Apakah Anda yakin ingin menonaktifkan 2FA? Anda akan perlu mengaturnya lagi untuk mengaktifkannya kembali.')) {
                return;
            }

            try {
                const response = await fetch('{{ route("2fa.disable") }}', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();

                if (!response.ok) {
                    alert('Gagal menonaktifkan 2FA: ' + (data.message || 'Unknown error'));
                    return;
                }

                // Reload page to update UI
                location.reload();
            } catch (error) {
                alert('Kesalahan jaringan: ' + error.message);
            }
        });
    }

    // Copy key button
    if (copyKeyBtn) {
        copyKeyBtn.addEventListener('click', function() {
            const key = document.getElementById('manualKey').textContent;
            navigator.clipboard.writeText(key).then(function() {
                const oldText = copyKeyBtn.innerHTML;
                copyKeyBtn.innerHTML = '<i class="bi bi-check"></i> Disalin';
                setTimeout(() => {
                    copyKeyBtn.innerHTML = oldText;
                }, 2000);
            });
        });
    }

    // Copy recovery codes button
    if (copyRecoveryCodesBtn) {
        copyRecoveryCodesBtn.addEventListener('click', function() {
            const codesText = document.getElementById('recoveryCodesList').textContent;
            navigator.clipboard.writeText(codesText).then(function() {
                const oldText = copyRecoveryCodesBtn.innerHTML;
                copyRecoveryCodesBtn.innerHTML = '<i class="bi bi-check"></i> Disalin';
                setTimeout(() => {
                    copyRecoveryCodesBtn.innerHTML = oldText;
                }, 2000);
            });
        });
    }

    // Finish setup button
    if (finishSetupBtn) {
        finishSetupBtn.addEventListener('click', function() {
            document.getElementById('twoFactorSetupProgress').style.display = 'none';
            document.getElementById('twoFactorActive').style.display = 'block';
            location.reload();
        });
    }

    // Helper function to show feedback
    function showFeedback(elementId, message, type) {
        const element = document.getElementById(elementId);
        element.className = 'alert alert-' + type;
        element.innerHTML = message;
    }
});
</script>
