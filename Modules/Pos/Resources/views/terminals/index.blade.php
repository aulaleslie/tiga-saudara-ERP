@extends('layouts.app')

@section('title', 'POS Terminals')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>POS Terminals</span>
                <a href="{{ route('pos.terminals.create') }}" class="btn btn-primary btn-sm">Add Terminal</a>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0 table-striped">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Policy</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($terminals as $terminal)
                            <tr>
                                <td>{{ $terminal->code }}</td>
                                <td>{{ $terminal->name }}</td>
                                <td>{{ optional($terminal->location)->name ?? '-' }}</td>
                                <td>
                                    @if($terminal->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($terminal->policy)
                                        Session: {{ $terminal->policy->require_session_open ? 'On' : 'Off' }}<br>
                                        Float: {{ $terminal->policy->require_opening_float ? 'On' : 'Off' }}<br>
                                        Cash Threshold: {{ $terminal->policy->cash_threshold !== null ? number_format((float) $terminal->policy->cash_threshold, 0) : '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('pos.terminals.edit', $terminal->id) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                    @if($terminal->is_active)
                                        <form action="{{ route('pos.terminals.destroy', $terminal->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Deactivate this terminal?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Deactivate</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4">No terminals configured yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
