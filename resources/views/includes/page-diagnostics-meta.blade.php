<meta name="erp-page-view-id" content="{{ (string) \Illuminate\Support\Str::uuid() }}">
<meta name="erp-page-rendered-at" content="{{ now()->toIso8601String() }}">
<meta name="erp-page-business-id" content="{{ session('setting_id') }}">
<meta name="erp-page-route" content="{{ optional(request()->route())->getName() }}">
