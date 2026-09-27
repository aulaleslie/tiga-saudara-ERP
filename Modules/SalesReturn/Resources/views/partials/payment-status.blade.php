@if (\App\Constants\PaymentStatus::matches($data->payment_status, \App\Constants\PaymentStatus::PAID))
    <span class="badge bg-success text-uppercase">{{ \App\Constants\PaymentStatus::label($data->payment_status) }}</span>
@elseif (\App\Constants\PaymentStatus::matches($data->payment_status, \App\Constants\PaymentStatus::PARTIAL))
    <span class="badge bg-warning text-dark text-uppercase">{{ \App\Constants\PaymentStatus::label($data->payment_status) }}</span>
@else
    <span class="badge bg-danger text-uppercase">{{ \App\Constants\PaymentStatus::label($data->payment_status) ?: 'Belum Dibayar' }}</span>
@endif

