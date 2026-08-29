@php
    $canOverrideReporting = auth()->user()->can('overrideReportingDate', $document);
    $canOverrideDue = auth()->user()->can('overrideDueDate', $document);
    $hasAnyPermission = $canOverrideReporting || $canOverrideDue;
    $isPurchase = $document instanceof \Modules\Purchase\Entities\Purchase;
    $updateRoute = $isPurchase ? route('purchases.date-adjustment.update', $document->id) : route('sales.date-adjustment.update', $document->id);
@endphp

@if($hasAnyPermission && !(isset($globalMode) && $globalMode))
    <button type="button" id="dateAdjustmentModalButton" class="btn btn-secondary" data-toggle="modal" data-target="#dateAdjustmentModal">
        <i class="bi bi-calendar-event mr-2"></i> Penyesuaian Tanggal
    </button>
@endif

{{-- Combined Audit History --}}
@if(($canOverrideReporting && ($document->reportingDateAudits->isNotEmpty() || $document->reporting_date)) || ($canOverrideDue && $document->dueDateAudits->isNotEmpty()))
    <div class="card mt-4 text-start">
        <div class="card-header">
            <h5 class="mb-0">Riwayat Penyesuaian Tanggal</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Jenis Field</th>
                            <th>Nilai Sebelum</th>
                            <th>Nilai Sesudah</th>
                            <th>Alasan</th>
                            <th>Petugas</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $allAudits = collect();
                            if ($canOverrideReporting) {
                                foreach ($document->reportingDateAudits as $audit) {
                                    $allAudits->push([
                                        'type' => 'Tanggal Pelaporan',
                                        'prior' => $audit->prior_override ? \Carbon\Carbon::parse($audit->prior_override)->format('d M, Y') : ($audit->original_date ? \Carbon\Carbon::parse($audit->original_date)->format('d M, Y') . ' (Asli)' : '-'),
                                        'resulting' => $audit->resulting_override ? \Carbon\Carbon::parse($audit->resulting_override)->format('d M, Y') : 'Dihapus (Kembali ke Asli)',
                                        'reason' => $audit->reason,
                                        'actor' => $audit->actor->name ?? '-',
                                        'created_at' => $audit->created_at,
                                    ]);
                                }
                            }
                            if ($canOverrideDue) {
                                foreach ($document->dueDateAudits as $audit) {
                                    $allAudits->push([
                                        'type' => 'Tanggal Jatuh Tempo',
                                        'prior' => $audit->prior_due_date ? \Carbon\Carbon::parse($audit->prior_due_date)->format('d M, Y') : '-',
                                        'resulting' => $audit->resulting_due_date ? \Carbon\Carbon::parse($audit->resulting_due_date)->format('d M, Y') : '-',
                                        'reason' => $audit->reason,
                                        'actor' => $audit->actor->name ?? '-',
                                        'created_at' => $audit->created_at,
                                    ]);
                                }
                            }
                            $sortedAudits = $allAudits->sortByDesc('created_at');
                        @endphp

                        @forelse($sortedAudits as $auditItem)
                            <tr>
                                <td><span class="badge {{ $auditItem['type'] === 'Tanggal Jatuh Tempo' ? 'badge-warning' : 'badge-info' }}">{{ $auditItem['type'] }}</span></td>
                                <td>{{ $auditItem['prior'] }}</td>
                                <td>{{ $auditItem['resulting'] }}</td>
                                <td>{{ $auditItem['reason'] }}</td>
                                <td>{{ $auditItem['actor'] }}</td>
                                <td>{{ $auditItem['created_at']->format('d M, Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">Belum ada riwayat penyesuaian tanggal</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

{{-- Date Adjustment Modal --}}
@if($hasAnyPermission && !(isset($globalMode) && $globalMode))
    <div class="modal fade" id="dateAdjustmentModal" tabindex="-1" role="dialog" aria-labelledby="dateAdjustmentModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content text-start">
                <div class="modal-header">
                    <h5 class="modal-title" id="dateAdjustmentModalLabel">Penyesuaian Tanggal Dokumen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="dateAdjustmentErrorAlert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="alert alert-info small mb-3">
                        <strong>Tanggal Dokumen Asli:</strong> {{ \Carbon\Carbon::parse($document->date)->format('d M, Y') }}<br>
                        <strong>Tanggal Pelaporan Saat Ini:</strong> {{ $document->reporting_date ? \Carbon\Carbon::parse($document->reporting_date)->format('d M, Y') : \Carbon\Carbon::parse($document->date)->format('d M, Y') . ' (Default)' }}<br>
                        <strong>Tanggal Jatuh Tempo Saat Ini:</strong> {{ $document->due_date ? \Carbon\Carbon::parse($document->due_date)->format('d M, Y') : '-' }}
                    </div>

                    <form id="dateAdjustmentForm">
                        @csrf
                        @if($canOverrideReporting)
                            <div class="card mb-3 p-3 bg-light border">
                                <h6 class="font-weight-bold text-primary mb-2">Tanggal Pelaporan</h6>
                                <div class="form-group mb-2">
                                    <label for="reporting_action" class="small">Aksi Tanggal Pelaporan</label>
                                    <select id="reporting_action" name="reporting_action" class="form-control form-control-sm">
                                        <option value="keep">Biarkan (Tidak Berubah)</option>
                                        <option value="set">Set/Ubah Tanggal</option>
                                        @if($document->reporting_date)
                                            <option value="clear">Hapus Override (Kembali ke Tanggal Asli)</option>
                                        @endif
                                    </select>
                                </div>
                                <div class="form-group mb-0 d-none" id="reportingDateInputGroup">
                                    <label for="reporting_date_val" class="small">Tanggal Pelaporan Baru <span class="text-danger">*</span></label>
                                    <input type="date" id="reporting_date_val" name="reporting_date" class="form-control form-control-sm" value="{{ $document->reporting_date ? \Carbon\Carbon::parse($document->reporting_date)->format('Y-m-d') : '' }}">
                                </div>
                            </div>
                        @else
                            <input type="hidden" name="reporting_action" value="keep">
                        @endif

                        @if($canOverrideDue)
                            <div class="card mb-3 p-3 bg-light border">
                                <h6 class="font-weight-bold text-warning mb-2">Tanggal Jatuh Tempo</h6>
                                <div class="form-group mb-2">
                                    <label for="due_date_action" class="small">Aksi Tanggal Jatuh Tempo</label>
                                    <select id="due_date_action" name="due_date_action" class="form-control form-control-sm">
                                        <option value="keep">Biarkan (Tidak Berubah)</option>
                                        <option value="set">Ubah Tanggal Jatuh Tempo</option>
                                    </select>
                                </div>
                                <div class="form-group mb-0 d-none" id="dueDateInputGroup">
                                    <label for="due_date_val" class="small">Tanggal Jatuh Tempo Baru <span class="text-danger">*</span></label>
                                    <input type="date" id="due_date_val" name="due_date" class="form-control form-control-sm" value="{{ $document->due_date ? \Carbon\Carbon::parse($document->due_date)->format('Y-m-d') : '' }}">
                                    <small class="form-text text-muted">Dapat memilih tanggal sebelum, sama dengan, atau setelah tanggal transaksi.</small>
                                </div>
                            </div>
                        @else
                            <input type="hidden" name="due_date_action" value="keep">
                        @endif

                        <div class="form-group">
                            <label for="adjustment_reason">Alasan Penyesuaian <span class="text-danger">*</span></label>
                            <textarea id="adjustment_reason" name="reason" class="form-control" rows="3" required maxlength="255" placeholder="Masukkan alasan penyesuaian tanggal..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Simpan Penyesuaian Tanggal</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @push('page_scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const reportingActionSelect = document.getElementById('reporting_action');
                const reportingGroup = document.getElementById('reportingDateInputGroup');
                const dueDateActionSelect = document.getElementById('due_date_action');
                const dueGroup = document.getElementById('dueDateInputGroup');
                const form = document.getElementById('dateAdjustmentForm');
                const errorAlert = document.getElementById('dateAdjustmentErrorAlert');

                if (reportingActionSelect) {
                    reportingActionSelect.addEventListener('change', function () {
                        if (this.value === 'set') {
                            reportingGroup.classList.remove('d-none');
                        } else {
                            reportingGroup.classList.add('d-none');
                        }
                    });
                }

                if (dueDateActionSelect) {
                    dueDateActionSelect.addEventListener('change', function () {
                        if (this.value === 'set') {
                            dueGroup.classList.remove('d-none');
                        } else {
                            dueGroup.classList.add('d-none');
                        }
                    });
                }

                if (form) {
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        errorAlert.classList.add('d-none');
                        errorAlert.textContent = '';

                        const reportingAction = reportingActionSelect ? reportingActionSelect.value : 'keep';
                        const reportingDate = document.getElementById('reporting_date_val') ? document.getElementById('reporting_date_val').value : '';
                        const dueDateAction = dueDateActionSelect ? dueDateActionSelect.value : 'keep';
                        const dueDate = document.getElementById('due_date_val') ? document.getElementById('due_date_val').value : '';
                        const reason = document.getElementById('adjustment_reason').value;

                        if (reportingAction === 'keep' && dueDateAction === 'keep') {
                            errorAlert.textContent = 'Pilih setidaknya satu perubahan tanggal (Tanggal Pelaporan atau Tanggal Jatuh Tempo)';
                            errorAlert.classList.remove('d-none');
                            return;
                        }

                        if (reportingAction === 'set' && !reportingDate) {
                            errorAlert.textContent = 'Tanggal pelaporan baru wajib diisi jika memilih ubah tanggal pelaporan.';
                            errorAlert.classList.remove('d-none');
                            return;
                        }

                        if (dueDateAction === 'set' && !dueDate) {
                            errorAlert.textContent = 'Tanggal jatuh tempo baru wajib diisi jika memilih ubah tanggal jatuh tempo.';
                            errorAlert.classList.remove('d-none');
                            return;
                        }

                        if (!reason.trim()) {
                            errorAlert.textContent = 'Alasan wajib diisi.';
                            errorAlert.classList.remove('d-none');
                            return;
                        }

                        fetch('{{ $updateRoute }}', {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            },
                            body: JSON.stringify({
                                reporting_action: reportingAction,
                                reporting_date: reportingDate,
                                due_date_action: dueDateAction,
                                due_date: dueDate,
                                reason: reason,
                            }),
                        })
                        .then(response => {
                            if (!response.ok) {
                                return response.json().then(data => {
                                    throw new Error(data.message || 'Terjadi kesalahan server');
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil',
                                    text: data.message || 'Penyesuaian tanggal berhasil disimpan',
                                    toast: true,
                                    position: 'top-end',
                                    showConfirmButton: false,
                                    timer: 2000,
                                });
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                errorAlert.textContent = data.message || 'Terjadi kesalahan';
                                errorAlert.classList.remove('d-none');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            errorAlert.textContent = error.message || 'Terjadi kesalahan saat mengirim data';
                            errorAlert.classList.remove('d-none');
                        });
                    });
                }
            });
        </script>
    @endpush
@endif
