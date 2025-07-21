@extends('layouts.app')

@section('title', 'Buat Metode Pembayaran')

@section('content')
    <div class="container">
        <form action="{{ route('payment-methods.store') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="name">Metode Pembayaran</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <label for="coa_id">Akun Jurnal</label>
                <select name="coa_id" id="coa_id" class="form-control @error('coa_id') is-invalid @enderror" required>
                    <option value="">Select COA</option>
                    @foreach ($chartOfAccounts as $coa)
                        <option value="{{ $coa->id }}" {{ old('coa_id') == $coa->id ? 'selected' : '' }}>
                            {{ $coa->name }}
                        </option>
                    @endforeach
                </select>
                @error('coa_id')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
            <a href="{{ route('payment-methods.index') }}" class="btn btn-secondary">Batal</a>
        </form>
    </div>
@endsection
