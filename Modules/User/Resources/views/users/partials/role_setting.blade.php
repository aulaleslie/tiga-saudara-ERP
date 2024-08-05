<div>
    @if(isset($settings) && isset($roles))
        @foreach($settings as $index => $setting)
            <div class="mb-2">
                <span class="badge badge-info">{{ $setting }}</span>
                <span class="badge badge-primary">{{ $roles[$index] }}</span>
            </div>
        @endforeach
    @endif
</div>
