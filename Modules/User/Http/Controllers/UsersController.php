<?php

namespace Modules\User\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Modules\Setting\Entities\Setting;
use Modules\User\DataTables\UsersDataTable;
use App\Models\User;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Upload\Entities\Upload;
use Spatie\Permission\Models\Role;

class UsersController extends Controller
{
    public function index(UsersDataTable $dataTable) {
        abort_if(Gate::denies('users.access'), 403);

        return $dataTable->render('user::users.index');
    }

    public function create() {
        abort_if(Gate::denies('users.create'), 403);

        return view('user::users.create');
    }

    public function store(Request $request) {
        abort_if(Gate::denies('users.create'), 403);

        // Validation rules
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:255|confirmed',
            'settings' => 'required|array|min:1',
            'settings.*' => 'integer|exists:settings,id',
            'roles' => 'required|array',
            'roles.*' => 'required|string|exists:roles,name',
        ]);

        // Ensure each selected setting has an associated role
        foreach ($validatedData['settings'] as $settingId) {
            if (!isset($validatedData['roles'][$settingId])) {
                return back()->withErrors(['roles' => 'Anda harus memilih peran untuk setiap setting yang dipilih.']);
            }
        }

        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'is_active' => $request->is_active,
        ]);

        $user->assignRole($request->role);

        if ($request->has('image')) {
            $tempFile = Upload::where('folder', $request->image)->first();

            if ($tempFile) {
                $user->addMedia(Storage::path('temp/' . $request->image . '/' . $tempFile->filename))->toMediaCollection('avatars');

                Storage::deleteDirectory('temp/' . $request->image);
                $tempFile->delete();
            }
        }

        foreach ($validatedData['settings'] as $settingId) {
            $roleName = $validatedData['roles'][$settingId];
            $role = Role::findByName($roleName);
            $user->assignRole($role);

            $setting = Setting::find($settingId);
            $user->settings()->attach($setting, ['role_id' => $role->id]);
        }

        toast('Pengguna berhasil dibuat!', 'success');

        return redirect()->route('users.index');
    }

    public function edit(User $user): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('users.edit'), 403);

        return view('user::users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if(Gate::denies('users.edit'), 403);

        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|max:255|confirmed',
            'settings' => 'required|array',
            'settings.*' => 'exists:settings,id',
            'roles' => 'required|array',
            'roles.*' => 'required|string|exists:roles,name',
            'is_active' => 'required|boolean',
        ], [
            'settings.required' => 'Anda harus memilih setidaknya satu setting.',
            'roles.required' => 'Anda harus memilih peran untuk setiap setting yang dipilih.',
        ]);

        $updateData = [
            'name'     => $request->name,
            'email'    => $request->email,
            'is_active' => $request->is_active,
        ];

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $user->update($updateData);

        // Sync user settings and roles
        $settings = $request->input('settings', []);
        $roles = $request->input('roles', []);
        $userSettings = [];
        foreach ($settings as $settingId) {
            $roleId = Role::where('name', $roles[$settingId])->first()->id;
            $userSettings[$settingId] = ['role_id' => $roleId];
        }
        $user->settings()->sync($userSettings);

        if ($request->has('image')) {
            $tempFile = Upload::where('folder', $request->image)->first();

            if ($user->getFirstMedia('avatars')) {
                $user->getFirstMedia('avatars')->delete();
            }

            if ($tempFile) {
                $user->addMedia(Storage::path('temp/' . $request->image . '/' . $tempFile->filename))->toMediaCollection('avatars');

                Storage::deleteDirectory('temp/' . $request->image);
                $tempFile->delete();
            }
        }

        toast("Perubahan Pengguna Berhasil!", 'info');

        return redirect()->route('users.index');
    }

    public function destroy(User $user) {
        abort_if(Gate::denies('users.delete'), 403);

        $user->delete();

        toast('Akun Dihapus!', 'warning');

        return redirect()->route('users.index');
    }
}
