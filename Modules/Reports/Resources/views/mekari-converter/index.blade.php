@extends('layouts.app')

@section('title', 'Mekari Converter')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Sales Report</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-8 col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <strong>Upload Sales CSV</strong>
                    </div>
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger">
                                {{ $errors->first('report_file') }} {{ $errors->first('filtered_csv') }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('reports.mekari-converter.handle') }}" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="report_file" class="form-label">Pilih File CSV</label>
                                <input type="file" class="form-control" name="report_file" id="report_file" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                Filter Pajak Saja & Download CSV
                            </button>
                        </form>

                        <hr class="my-4">

                        <form method="POST" action="{{ route('reports.mekari-converter.formatted-xlsx') }}" enctype="multipart/form-data" class="mt-4">
                            @csrf
                            <div class="mb-3">
                                <label for="filtered_csv">Upload Filtered CSV</label>
                                <input type="file" name="filtered_csv" class="form-control" required accept=".csv">
                            </div>
                            <button type="submit" class="btn btn-success mt-2">Convert ke XLSX</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
