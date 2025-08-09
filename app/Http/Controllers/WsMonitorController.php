<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Pusher\Pusher;

class WsMonitorController extends Controller
{
    private function client(): Pusher
    {
        return new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'host'    => env('PUSHER_HOST', '127.0.0.1'),
                'port'    => (int) env('PUSHER_PORT', 6001),
                'scheme'  => env('PUSHER_SCHEME', 'http'),
                'useTLS'  => false,
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'timeout' => 2,
            ]
        );
    }

    public function index()
    {
        return view('admin.ws-monitor');
    }

    public function data(Request $request)
    {
        $prefix = $request->query('prefix', '');
        $channels = $this->client()->get_channels([
            'filter_by_prefix' => $prefix,
            'info' => 'user_count,subscription_count'
        ]);

        return response()->json([
            'now' => now()->toIso8601String(),
            'channels' => $channels->channels ?? new \stdClass(),
        ]);
    }

    public function presence(string $name)
    {
        // Only works for presence- channels
        $members = $this->client()->get_presence_users($name);
        return response()->json($members);
    }
}
