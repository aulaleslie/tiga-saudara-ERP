@php use Modules\Adjustment\Entities\Transfer; @endphp
{{-- Approval summary content, shared by the modal and the inline fallback. --}}
<p class="mb-2">Kondisi: <strong>{{ $summary['condition'] === Transfer::CONDITION_BREAKAGE ? 'Barang Rusak' : 'Barang Baik' }}</strong></p>
<div class="v3-table-scroll mb-3">
<table class="table table-sm table-bordered mb-0 v3-alloc-table v3-summary-table">
    <colgroup>
        <col class="v3-col-product">
        <col class="v3-col-from">
        <col class="v3-col-to">
        <col class="v3-col-count">
        <col class="v3-col-serials">
    </colgroup>
    <thead>
    <tr><th>Produk</th><th>Dari</th><th>Ke</th><th class="text-center">Jumlah</th><th>Nomor Seri</th></tr>
    </thead>
    <tbody>
    @foreach($summary['rows'] as $row)
        <tr>
            <td class="v3-wrap">{{ $row['product_name'] }}</td>
            <td class="v3-wrap">{{ $row['source'] }}</td>
            <td class="v3-wrap">
                {{ $row['destination'] ?? '—' }}
                @if($row['cross_business'])<span class="badge badge-warning ml-1">Lintas Bisnis</span>@endif
            </td>
            <td class="text-center">{{ $row['quantity'] }}</td>
            <td class="v3-wrap">{{ implode(', ', $row['serials']) ?: '-' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@if($summary['errors'] !== [])
    <div class="alert alert-danger mb-0">
        <strong>Alokasi belum dapat disetujui:</strong>
        <ul class="mb-0">
            @foreach($summary['errors'] as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@else
    <div class="alert alert-info mb-0">Konfirmasi akan menyetujui dan langsung mengirim seluruh alokasi di atas secara bersamaan.</div>
@endif
