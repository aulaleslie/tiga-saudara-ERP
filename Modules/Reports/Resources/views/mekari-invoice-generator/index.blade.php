@extends('layouts.app')

@section('title', 'Generate Sales Invoices')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Generate Invoices</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header"><strong>Upload Sales & Customer CSV</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('reports.mekari-invoice-generator.generate') }}"
                      enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="sales_csv" class="form-label">Filtered Sales CSV</label>
                        <input type="file" name="sales_csv" class="form-control" accept=".csv" required>
                    </div>
                    <div class="mb-3">
                        <label for="contacts_csv" class="form-label">Customer Contact CSV</label>
                        <input type="file" name="contacts_csv" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Generate & Download ZIP</button>
                </form>
            </div>
        </div>
    </div>
@endsection
