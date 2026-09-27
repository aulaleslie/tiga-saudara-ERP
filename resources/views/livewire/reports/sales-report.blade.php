<div>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form wire:submit="generateReport">
                        <div class="form-row">
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>Start Date <span class="text-danger">*</span></label>
                                    <input wire:model="start_date" type="date" class="form-control" name="start_date">
                                    @error('start_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>End Date <span class="text-danger">*</span></label>
                                    <input wire:model="end_date" type="date" class="form-control" name="end_date">
                                    @error('end_date')
                                    <span class="text-danger mt-1">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-group">
                                    <label>Customer</label>
                                    <select wire:model="customer_id" class="form-control" name="customer_id">
                                        <option value="">Select Customer</option>
                                        @foreach($customers as $customer)
                                            <option value="{{ $customer->id }}">{{ $customer->customer_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="col-lg-6">
                                 <div class="form-group">
                                     <label>Status</label>
                                     <select wire:model="sale_status" class="form-control" name="sale_status">
                                         <option value="">Pilih Status</option>
                                         <option value="Pending">Menunggu</option>
                                         <option value="Shipped">Dikirim</option>
                                         <option value="Completed">Selesai</option>
                                     </select>
                                 </div>
                            </div>
                            <div class="col-lg-6">
                                 <div class="form-group">
                                     <label>Status Pembayaran</label>
                                     <select wire:model="payment_status" class="form-control" name="payment_status">
                                         <option value="">Pilih Status Pembayaran</option>
                                         <option value="Paid">Lunas</option>
                                         <option value="Unpaid">Belum Dibayar</option>
                                         <option value="Partial">Dibayar Sebagian</option>
                                     </select>
                                 </div>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <button type="submit" class="btn btn-primary">
                                <span wire:target="generateReport" wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <i wire:target="generateReport" wire:loading.remove class="bi bi-shuffle"></i>
                                Filter Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <table class="table table-bordered table-striped text-center mb-0">
                        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center" style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="sr-only">Loading...</span>
                            </div>
                        </div>
                        <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Referensi</th>
                            <th>Pelanggan</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Dibayar</th>
                            <th>Sisa Tagihan</th>
                            <th>Status Pembayaran</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($sales as $sale)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($sale->effective_date)->format('d M, Y') }}</td>
                                <td>{{ $sale->reference }}</td>
                                <td>{{ $sale->customer_name }}</td>
                                <td>
                                    @php
                                        $sStatusLabel = \Modules\Sale\Entities\Sale::STATUS_LABELS[$sale->status] ?? $sale->status;
                                        if ($sale->status === 'Pending') {
                                            $sStatusLabel = 'Menunggu';
                                        } elseif ($sale->status === 'Shipped') {
                                            $sStatusLabel = 'Dikirim';
                                        } elseif ($sale->status === 'Completed') {
                                            $sStatusLabel = 'Selesai';
                                        }
                                    @endphp
                                    @if ($sale->status == 'Pending')
                                        <span class="badge badge-info">
                                            {{ $sStatusLabel }}
                                        </span>
                                    @elseif ($sale->status == 'Shipped')
                                        <span class="badge badge-primary">
                                            {{ $sStatusLabel }}
                                        </span>
                                    @else
                                        <span class="badge badge-success">
                                            {{ $sStatusLabel }}
                                        </span>
                                    @endif
                                </td>
                                <td>{{ format_currency($sale->total_amount) }}</td>
                                <td>{{ format_currency($sale->paid_amount) }}</td>
                                <td>{{ format_currency($sale->due_amount) }}</td>
                                <td>
                                    @if (\App\Constants\PaymentStatus::matches($sale->payment_status, \App\Constants\PaymentStatus::PARTIAL))
                                        <span class="badge badge-warning">
                                            {{ \App\Constants\PaymentStatus::label($sale->payment_status) }}
                                        </span>
                                    @elseif (\App\Constants\PaymentStatus::matches($sale->payment_status, \App\Constants\PaymentStatus::PAID))
                                        <span class="badge badge-success">
                                            {{ \App\Constants\PaymentStatus::label($sale->payment_status) }}
                                        </span>
                                    @else
                                        <span class="badge badge-danger">
                                            {{ \App\Constants\PaymentStatus::label($sale->payment_status) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <span class="text-danger">No Sales Data Available!</span>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                    <div @class(['mt-3' => $sales->hasPages()])>
                        {{ $sales->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
