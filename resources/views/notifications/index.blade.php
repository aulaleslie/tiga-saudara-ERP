@extends('layouts.app')

@section('title', 'Notifikasi')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Semua Notifikasi</h4>
                        <form action="{{ route('notifications.markAllRead') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary btn-sm">Tandai Semua Dibaca</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            @forelse($notifications as $notification)
                                <a href="{{ route('notifications.read', $notification->id) }}" class="list-group-item list-group-item-action {{ is_null($notification->read_at) ? 'bg-light' : '' }}">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h5 class="mb-1">
                                            @if(is_null($notification->read_at))
                                                <i class="bi bi-circle-fill text-primary mr-2" style="font-size: 10px;"></i>
                                            @endif
                                            {{ $notification->title }}
                                        </h5>
                                        <small class="text-muted">{{ $notification->created_at->diffForHumans() }}</small>
                                    </div>
                                    <p class="mb-1">{{ $notification->message }}</p>
                                    @if($notification->setting)
                                        <small class="text-muted">Bisnis: {{ $notification->setting->company_name }}</small>
                                    @endif
                                </a>
                            @empty
                                <div class="p-4 text-center">
                                    <h5 class="text-muted">Tidak ada notifikasi</h5>
                                </div>
                            @endforelse
                        </div>
                    </div>
                    @if($notifications->hasPages())
                        <div class="card-footer d-flex justify-content-center">
                            {{ $notifications->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
