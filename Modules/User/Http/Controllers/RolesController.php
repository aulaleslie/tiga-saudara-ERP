<?php

namespace Modules\User\Http\Controllers;

use App\Services\IdempotencyService;
use Modules\User\DataTables\RolesDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }
    public function index(RolesDataTable $dataTable) {
        abort_if(Gate::denies('roles.access'), 403);

        return $dataTable->render('user::roles.index');
    }


    public function create(Request $request) {
        abort_if(Gate::denies('roles.create'), 403);

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('user::roles.create', compact('idempotencyToken'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('roles.create'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'required|array'
        ]);

        $role = Role::create([
            'name' => $request->name
        ]);

        $role->givePermissionTo($request->permissions);

        toast('Role Created With Selected Permissions!', 'success');

        return redirect()->route('roles.index');
    }


    public function edit(Role $role) {
        abort_if(Gate::denies('roles.edit'), 403);

        return view('user::roles.edit', compact('role'));
    }


    public function update(Request $request, Role $role) {
        abort_if(Gate::denies('roles.edit'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'required|array'
        ]);

        $role->update([
            'name' => $request->name
        ]);

        $role->syncPermissions($request->permissions);

        toast('Hak Akses Peran telah diperbarui!', 'success');

        return redirect()->route('roles.index');
    }


    public function destroy(Role $role) {
        abort_if(Gate::denies('roles.delete'), 403);

        $role->delete();

        toast('Peran Dihapus!', 'success');

        return redirect()->route('roles.index');
    }
}
