@if (\App\Constants\PaymentStatus::matches($data->payment_status, \App\Constants\PaymentStatus::PARTIAL))
    <span class="badge badge-warning">
        {{ \App\Constants\PaymentStatus::label($data->payment_status) }}
    </span>
@elseif (\App\Constants\PaymentStatus::matches($data->payment_status, \App\Constants\PaymentStatus::PAID))
    <span class="badge badge-success">
        {{ \App\Constants\PaymentStatus::label($data->payment_status) }}
    </span>
@else
    <span class="badge badge-danger">
        {{ \App\Constants\PaymentStatus::label($data->payment_status) }}
    </span>
@endif
