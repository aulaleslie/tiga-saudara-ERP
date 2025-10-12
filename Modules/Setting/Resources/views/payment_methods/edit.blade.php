@extends('layouts.app')

@section('title', 'Ubah Metode Pembayaran')

@section('content')
    <div class="container">
        <h1>Ubah Metode Pembayaran</h1>

        <form action="{{ route('payment-methods.update', $paymentMethod->id) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="form-group">
                <label for="name">Nama Metode Pembayaran</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $paymentMethod->name) }}" required>
                @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <label for="coa_id">Akun Jurnal</label>
                <select name="coa_id" id="coa_id" class="form-control @error('coa_id') is-invalid @enderror" required>
                    <option value="">Select COA</option>
                    @foreach ($chartOfAccounts as $coa)
                        <option value="{{ $coa->id }}" {{ $paymentMethod->coa_id == $coa->id ? 'selected' : '' }}>
                            {{ $coa->name }}
                        </option>
                    @endforeach
                </select>
                @error('coa_id')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-check mb-2">
                <input type="hidden" name="is_cash" value="0">
                <input class="form-check-input" type="checkbox" id="is_cash" name="is_cash" value="1" {{ old('is_cash', $paymentMethod->is_cash) ? 'checked' : '' }}>
                <label class="form-check-label" for="is_cash">Metode Tunai</label>
            </div>
            @error('is_cash')
            <div class="text-danger small">{{ $message }}</div>
            @enderror
            <div class="form-check mb-3">
                <input type="hidden" name="is_available_in_pos" value="0">
                <input class="form-check-input" type="checkbox" id="is_available_in_pos" name="is_available_in_pos" value="1" {{ old('is_available_in_pos', $paymentMethod->is_available_in_pos) ? 'checked' : '' }}>
                <label class="form-check-label" for="is_available_in_pos">Tersedia di POS</label>
            </div>
            @error('is_available_in_pos')
            <div class="text-danger small">{{ $message }}</div>
            @enderror
            <button type="submit" class="btn btn-success">Update</button>
            <a href="{{ route('payment-methods.index') }}" class="btn btn-secondary">Batal</a>
        </form>
    </div>
@endsection
