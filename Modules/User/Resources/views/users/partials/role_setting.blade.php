<div>
    @if(isset($roleSettings))
        @foreach($roleSettings as $roleSetting)
            <div class="mb-2">
                <span class="badge badge-info">{{ $roleSetting['setting'] }}</span>
                <span class="badge badge-primary">{{ $roleSetting['role'] }}</span>
            </div>
        @endforeach
    @endif
</div>
