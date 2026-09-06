<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return User::query()->with(['roles', 'departments', 'warehouse', 'extraPermissions'])->orderBy('name_en')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name_en' => ['required', 'string'],
            'name_ar' => ['required', 'string'],
            'civil_id' => ['required', 'digits:12', 'unique:users,civil_id'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
            'is_active' => ['boolean'],
        ]);

        $user = User::query()->create([
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'civil_id' => $data['civil_id'],
            'email' => ! empty($data['email']) ? $data['email'] : null,
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->roles()->sync($data['role_ids'] ?? []);
        $user->departments()->sync($data['department_ids'] ?? []);
        $user->extraPermissions()->sync($data['permission_ids'] ?? []);

        if ($user->hasRole('technician') && ! $user->warehouse) {
            Warehouse::query()->create([
                'name' => $user->name_en.' van',
                'type' => 'technician',
                'technician_id' => $user->id,
            ]);
        }

        return response()->json($user->load(['roles', 'departments', 'warehouse', 'extraPermissions']), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name_en' => ['sometimes', 'string'],
            'name_ar' => ['sometimes', 'string'],
            'civil_id' => ['sometimes', 'digits:12', Rule::unique('users', 'civil_id')->ignore($user->id)],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'min:8'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (array_key_exists('email', $data)) {
            $data['email'] = $data['email'] ?: null;
        }

        $user->fill(collect($data)->only(['name_en', 'name_ar', 'civil_id', 'email'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        if (isset($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }
        if (isset($data['department_ids'])) {
            $user->departments()->sync($data['department_ids']);
        }
        if (isset($data['permission_ids'])) {
            $user->extraPermissions()->sync($data['permission_ids']);
        }

        return $user->load(['roles', 'departments', 'warehouse', 'extraPermissions']);
    }

    public function toggleActive(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }
        $user->is_active = ! $user->is_active;
        $user->save();

        return $user;
    }

    public function roles()
    {
        return Role::query()->with('permissions')->withCount('users')->orderBy('name')->get();
    }

    public function permissions()
    {
        return Permission::query()
            ->with(['roles' => fn ($q) => $q->orderBy('name')])
            ->withCount('roles')
            ->orderBy('group')
            ->orderBy('name')
            ->get();
    }

    public function storePermission(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:permissions,slug'],
            'group' => ['nullable', 'string', 'max:255'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name'], '.');
        if ($slug === '' || Permission::query()->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'A permission with this name already exists.'], 422);
        }

        $permission = Permission::query()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'group' => $data['group'] ?: 'custom',
        ]);
        $permission->roles()->sync($this->permissionRoleIds($data['role_ids'] ?? []));

        return response()->json($permission->load('roles')->loadCount('roles'), 201);
    }

    public function updatePermission(Request $request, Permission $permission)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $permission->fill(collect($data)->only(['name', 'group'])->all());
        if (array_key_exists('group', $data) && ! $data['group']) {
            $permission->group = 'custom';
        }
        $permission->save();

        if (array_key_exists('role_ids', $data)) {
            $permission->roles()->sync($this->permissionRoleIds($data['role_ids']));
        }

        return $permission->load('roles')->loadCount('roles');
    }

    public function destroyPermission(Permission $permission)
    {
        if ($permission->users()->exists()) {
            return response()->json(['message' => 'Remove this extra permission from staff first.'], 422);
        }

        $permission->roles()->detach();
        $permission->delete();

        return response()->noContent();
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<int, int>
     */
    private function permissionRoleIds(array $roleIds): array
    {
        $adminId = Role::query()->where('slug', 'admin')->value('id');
        $ids = collect($roleIds)
            ->reject(fn ($id) => $id === $adminId)
            ->values()
            ->all();

        if ($adminId) {
            $ids[] = $adminId;
        }

        return array_values(array_unique($ids));
    }

    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:roles,slug', 'not_in:admin'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name'], '_');
        if ($slug === '' || $slug === 'admin') {
            return response()->json(['message' => 'Choose a different role name.'], 422);
        }
        if (Role::query()->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'A role with this name already exists.'], 422);
        }

        $role = Role::query()->create([
            'name' => $data['name'],
            'slug' => $slug,
        ]);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return response()->json($role->load('permissions')->loadCount('users'), 201);
    }

    public function updateRole(Request $request, Role $role)
    {
        if ($role->slug === 'admin' && $request->exists('permission_ids')) {
            return response()->json(['message' => 'Admin permissions cannot be changed.'], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if ($role->slug !== 'admin' && array_key_exists('permission_ids', $data)) {
            $role->permissions()->sync($data['permission_ids']);
        }

        return $role->load('permissions')->loadCount('users');
    }

    public function destroyRole(Role $role)
    {
        if ($role->slug === 'admin') {
            return response()->json(['message' => 'The admin role cannot be deleted.'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Reassign staff before deleting this role.'], 422);
        }

        $role->delete();

        return response()->noContent();
    }

    public function updateRolePermissions(Request $request, Role $role)
    {
        if ($role->slug === 'admin') {
            return response()->json(['message' => 'Admin permissions cannot be changed.'], 422);
        }

        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);
        $role->permissions()->sync($data['permission_ids']);

        return $role->load('permissions')->loadCount('users');
    }
}
