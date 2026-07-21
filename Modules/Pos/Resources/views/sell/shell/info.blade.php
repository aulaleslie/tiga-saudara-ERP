                <div class="pos-area pos-area-info">
                    <div class="card pos-card pos-thin-card">
                        <div class="card-body">
                            <span class="d-none">Layar Kasir POS</span>
                            <span class="d-none">Sesi #{{ $activeSession->id }}</span>
                            <div class="pos-info-strip">
                                <div class="pos-info-title"><i class="bi bi-person-circle"></i> {{ auth()->user()->name }}</div>
                                <div class="pos-info-metrics">
                                    <span class="pos-info-item"><i class="bi bi-hash d-inline d-md-none"></i><strong class="d-none d-md-inline">Sesi:</strong> #{{ $activeSession->id }}</span>
                                    <span class="pos-info-item" title="{{ $terminalLabelFull }}"><i class="bi bi-pc-display d-inline d-md-none"></i><strong class="d-none d-md-inline">Terminal:</strong> {{ $terminalLabelShort }}</span>
                                    <span class="pos-info-item"><i class="bi bi-clock d-inline d-md-none"></i><strong class="d-none d-md-inline">Dibuka:</strong> <span class="d-none d-md-inline">{{ optional($activeSession->opened_at)->format('Y-m-d H:i') ?? '-' }}</span><span class="d-inline d-md-none">{{ optional($activeSession->opened_at)->format('H:i') ?? '-' }}</span></span>
                                    <span class="pos-info-item"><strong>Status:</strong> {{ strtoupper($activeSession->status) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
