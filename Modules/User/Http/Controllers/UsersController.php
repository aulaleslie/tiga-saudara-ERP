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

    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('users.create'), 403);

        return view('user::users.create');
    }

    public function store(Request $request): RedirectResponse
    {
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
            'is_active' => 'required|boolean',
        ]);

        // Ensure each selected setting has an associated role
        foreach ($validatedData['settings'] as $settingId) {
            if (!isset($validatedData['roles'][$settingId])) {
                return back()->withErrors(['roles' => 'Anda harus memilih peran untuk setiap setting yang dipilih.']);
            }
        }

        // Create the user
        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'is_active' => $validatedData['is_active'],
        ]);

        // Assign the selected roles to the user
        foreach ($validatedData['settings'] as $settingId) {
            $roleName = $validatedData['roles'][$settingId];
            $role = Role::where('name', $roleName)->first();
            $user->assignRole($role);

            // Attach the setting with the associated role to the user
            $user->settings()->attach($settingId, ['role_id' => $role->id]);
        }

        // Handle image upload
        if ($request->has('image')) {
            $tempFile = Upload::where('folder', $request->image)->first();

            if ($tempFile) {
                $user->addMedia(Storage::path('temp/' . $request->image . '/' . $tempFile->filename))->toMediaCollection('avatars');

                Storage::deleteDirectory('temp/' . $request->image);
                $tempFile->delete();
            }
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

        $validatedData = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|max:255|confirmed',
            'settings' => 'required|array|min:1',
            'settings.*' => 'integer|exists:settings,id',
            'roles' => 'required|array',
            'roles.*' => 'required|string|exists:roles,name',
            'is_active' => 'required|boolean',
        ]);

        // Ensure each selected setting has an associated role
        foreach ($validatedData['settings'] as $settingId) {
            if (!isset($validatedData['roles'][$settingId])) {
                return back()->withErrors(['roles' => 'Anda harus memilih peran untuk setiap setting yang dipilih.']);
            }
        }

        // Update user data
        $updateData = [
            'name'     => $validatedData['name'],
            'email'    => $validatedData['email'],
            'is_active' => $validatedData['is_active'],
        ];

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $user->update($updateData);

        // Sync user settings and roles
        $userSettings = [];
        foreach ($validatedData['settings'] as $settingId) {
            $roleName = $validatedData['roles'][$settingId];
            $roleId = Role::where('name', $roleName)->first()->id;
            $userSettings[$settingId] = ['role_id' => $roleId];
        }
        $user->settings()->sync($userSettings);

        // Handle image upload
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

    public function destroy(User $user): RedirectResponse
    {
        abort_if(Gate::denies('users.delete'), 403);

        $user->delete();

        toast('Akun Dihapus!', 'warning');

        return redirect()->route('users.index');
    }
}
