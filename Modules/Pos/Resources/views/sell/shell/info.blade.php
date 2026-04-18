                <div class="pos-area pos-area-info">
                    <div class="card pos-card pos-thin-card">
                        <div class="card-body">
                            <span class="d-none">Layar Kasir POS</span>
                            <span class="d-none">Sesi #{{ $activeSession->id }}</span>
                            <div class="pos-info-strip">
                                <div class="pos-info-title">Kasir Information</div>
                                <div class="pos-info-metrics">
                                    <span class="pos-info-item"><strong>Sesi:</strong> #{{ $activeSession->id }}</span>
                                    <span class="pos-info-item" title="{{ $terminalLabelFull }}"><strong>Terminal:</strong> {{ $terminalLabelShort }}</span>
                                    <span class="pos-info-item"><strong>Dibuka:</strong> {{ optional($activeSession->opened_at)->format('Y-m-d H:i') ?? '-' }}</span>
                                    <span class="pos-info-item"><strong>Status:</strong> {{ strtoupper($activeSession->status) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
