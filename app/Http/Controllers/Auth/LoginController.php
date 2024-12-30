<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Setting\Entities\Setting;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    protected function authenticated(Request $request, $user)
    {
        if ($user->is_active != 1) {
            Auth::logout();

            return back()->with([
                'account_deactivated' => 'Your account is deactivated! Please contact the Super Admin.'
            ]);
        }

        if ($user->hasRole('Super Admin')) {
            // Get the first setting ordered by ID
            $defaultSetting = Setting::orderBy('id')->firstOrFail();
            $request->session()->put('setting_id', $defaultSetting->id);

            // Get all settings ordered by ID
            $userSettings = Setting::orderBy('id')->get();
            $request->session()->put('user_settings', $userSettings);
        } else {
            // Get the first setting ordered by ID for the user
            $defaultSetting = $user->settings()->orderBy('id')->firstOrFail();
            $request->session()->put('setting_id', $defaultSetting->id);

            // Get all user settings ordered by ID
            $userSettings = $user->settings()->orderBy('id')->get();
            $request->session()->put('user_settings', $userSettings);
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    public function apiLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($request->only('email', 'password'))) {
            $user = Auth::user();

            // Ensure the user is active
            if ($user->is_active != 1) {
                return response()->json([
                    'message' => 'Your account is deactivated! Please contact the Super Admin.',
                ], 403);
            }

            // Issue a Sanctum token
            $token = $user->createToken('api-token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user,
            ]);
        }

        return response()->json([
            'message' => 'Invalid credentials',
        ], 401);
    }

    public function apiLogout(Request $request)
    {
        // Revoke the token used to authenticate the request
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
