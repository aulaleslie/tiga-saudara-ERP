@extends('layouts.app')

@section('title', 'Products')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection



@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Produk</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can("products.create")
                            <a href="{{ route('products.create') }}" class="btn btn-primary">
                                Tambah Produk <i class="bi bi-plus"></i>
                            </a>
                            <a href="{{ route('products.imports.index') }}" class="btn btn-secondary">
                                Upload Produk <i class="bi bi-upload"></i>
                            </a>
                        @endcan

                        <hr>

                        <div id="product-status-feedback" aria-live="polite"></div>

                        <div class="btn-group mb-3" role="group" aria-label="Filter status produk">
                            <button type="button"
                                    class="btn btn-primary product-status-filter active"
                                    data-status="active"
                                    aria-pressed="true">
                                Produk Aktif
                            </button>
                            <button type="button"
                                    class="btn btn-outline-secondary product-status-filter"
                                    data-status="inactive"
                                    aria-pressed="false">
                                Produk Nonaktif
                            </button>
                        </div>

                        <div class="table-responsive">
                            {!! $dataTable->table() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    {!! $dataTable->scripts() !!}

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tableId = 'product-table';
            var indexUrl = @json(route('products.index'));
            var feedback = document.getElementById('product-status-feedback');

            function productTable() {
                return window.LaravelDataTables && window.LaravelDataTables[tableId];
            }

            function reloadProductTableAfterStatusChange() {
                var table = productTable();
                var pageInfo = table.page.info();
                var isOnlyRowOnCurrentPage = table.rows({ page: 'current' }).count() === 1;

                if (pageInfo.page > 0 && isOnlyRowOnCurrentPage) {
                    table.page('previous').draw('page');

                    return;
                }

                table.ajax.reload(null, false);
            }

            function showFeedback(message, type) {
                feedback.innerHTML = '';

                var alert = document.createElement('div');
                alert.className = 'alert alert-' + type + ' alert-dismissible fade show';
                alert.setAttribute('role', 'alert');
                alert.appendChild(document.createTextNode(message));

                var closeButton = document.createElement('button');
                closeButton.type = 'button';
                closeButton.className = 'close';
                closeButton.setAttribute('data-dismiss', 'alert');
                closeButton.setAttribute('aria-label', 'Tutup');
                closeButton.innerHTML = '<span aria-hidden="true">&times;</span>';

                alert.appendChild(closeButton);
                feedback.appendChild(alert);
            }

            document.querySelectorAll('.product-status-filter').forEach(function (button) {
                button.addEventListener('click', function () {
                    document.querySelectorAll('.product-status-filter').forEach(function (candidate) {
                        var selected = candidate === button;
                        candidate.classList.toggle('active', selected);
                        candidate.classList.toggle('btn-primary', selected);
                        candidate.classList.toggle('btn-outline-secondary', !selected);
                        candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');
                    });

                    var url = new URL(indexUrl, window.location.origin);
                    url.searchParams.set('status', button.dataset.status);
                    productTable().ajax.url(url.toString()).load();
                });
            });

            document.addEventListener('submit', async function (event) {
                var form = event.target.closest('.product-status-form');

                if (!form) {
                    return;
                }

                event.preventDefault();

                var isActive = form.dataset.productActive === '1';
                var actionLabel = isActive ? 'Nonaktifkan' : 'Aktifkan kembali';
                var detail = isActive
                    ? ' Produk tidak akan muncul untuk transaksi baru, namun data historis tetap aman.'
                    : '';

                if (!window.confirm(actionLabel + ' produk "' + form.dataset.productName + '"?' + detail)) {
                    return;
                }

                var submitButton = form.querySelector('button[type="submit"]');
                submitButton.disabled = true;

                try {
                    var response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    var payload = await response.json();

                    if (!response.ok) {
                        throw new Error(payload.message || 'Status produk gagal diperbarui.');
                    }

                    showFeedback(payload.message, payload.is_active ? 'success' : 'info');
                    reloadProductTableAfterStatusChange();
                } catch (error) {
                    showFeedback(error.message || 'Status produk gagal diperbarui.', 'danger');
                    submitButton.disabled = false;
                }
            });
        });
    </script>
@endpush
