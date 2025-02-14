@extends('layouts.app')

@section('title', 'Purchase Receivings')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="purchase-receivings-table" class="table table-striped table-bordered">
                                <thead>
                                <tr>
                                    <th></th> <!-- Expand Button -->
                                    <th>ID</th>
                                    <th>No. Delivery</th>
                                    <th>No. Invoice</th>
                                    <th>Tanggal</th>
                                    <th>Total Diterima</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($receivedNotes as $receivedNote)
                                    <!-- Main Row -->
                                    <tr>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary toggle-details"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#details-{{ $receivedNote->id }}"
                                                    aria-expanded="false"
                                                    aria-controls="details-{{ $receivedNote->id }}">
                                                <i class="bi bi-plus-circle"></i>
                                            </button>
                                        </td>
                                        <td>{{ $receivedNote->id }}</td>
                                        <td>{{ $receivedNote->external_delivery_number ?? '-' }}</td>
                                        <td>{{ $receivedNote->internal_invoice_number ?? '-' }}</td>
                                        <td>{{ optional($receivedNote->created_at)->format('Y-m-d') }}</td>
                                        <td>{{ $receivedNote->receivedNoteDetails->sum('quantity_received') }}</td>
                                    </tr>

                                    <!-- Expandable Details Row -->
                                    <tr id="details-{{ $receivedNote->id }}" class="collapse">
                                        <td colspan="6">
                                            @include('purchase::receivings.receiving-details', ['data' => $receivedNote])
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        $(document).ready(function () {
            // Initialize DataTables only for filtering & sorting
            let table = $('#purchase-receivings-table').DataTable({
                paging: false,  // Disable pagination since all data is preloaded
                searching: true,  // Enable search
                ordering: true,  // Enable sorting
                info: false  // Disable table info
            });

            // Handle Expand/Collapse
            $('#purchase-receivings-table tbody').on('click', 'button.toggle-details', function () {
                let icon = $(this).find('i');
                let rowId = $(this).attr('data-bs-target');

                if ($(rowId).hasClass('show')) {
                    icon.removeClass('bi-dash-circle').addClass('bi-plus-circle'); // Change to plus
                } else {
                    icon.removeClass('bi-plus-circle').addClass('bi-dash-circle'); // Change to minus
                }
            });
        });
    </script>
@endpush
