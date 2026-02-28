@extends('layouts.app')

@section('title', 'Buka Sesi POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Buka Sesi POS</div>
            <div class="card-body">
                <form method="POST" action="{{ route('pos.sessions.store') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="terminal_id" class="form-label">Terminal</label>
                                <select name="terminal_id" id="terminal_id" class="form-select @error('terminal_id') is-invalid @enderror" required>
                                    <option value="">-- Pilih Terminal --</option>
                                    @foreach($terminals as $terminal)
                                        <option value="{{ $terminal->id }}" @selected((string) old('terminal_id') === (string) $terminal->id)>
                                            {{ $terminal->code }} - {{ $terminal->name }}
                                            @if($terminal->location)
                                                ({{ $terminal->location->name }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('terminal_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="opening_float_total" class="form-label">Total Saldo Awal</label>
                                <input type="number" min="0.01" step="0.01" name="opening_float_total" id="opening_float_total"
                                       class="form-control @error('opening_float_total') is-invalid @enderror"
                                       value="{{ old('opening_float_total') }}" required>
                                @error('opening_float_total')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    @php
                        $denominations = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];
                    @endphp

                    <div class="mb-3">
                        <label class="form-label d-block">Pecahan Saldo Awal (Opsional jika terminal mengizinkan total saja)</label>
                        <div class="row">
                            @foreach($denominations as $denomination)
                                <div class="col-6 col-md-4 col-lg-3 mb-2">
                                    <label class="form-label small" for="denom_{{ $denomination }}">{{ number_format($denomination, 0, ',', '.') }}</label>
                                    <input type="number" min="0" step="1"
                                           id="denom_{{ $denomination }}"
                                           name="opening_denominations[{{ $denomination }}]"
                                           value="{{ old('opening_denominations.' . $denomination) }}"
                                           class="form-control @error('opening_denominations') is-invalid @enderror">
                                </div>
                            @endforeach
                        </div>
                        @error('opening_denominations')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Catatan</label>
                        <textarea name="notes" id="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Buka Sesi</button>
                        <a href="{{ route('pos.sell') }}" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
