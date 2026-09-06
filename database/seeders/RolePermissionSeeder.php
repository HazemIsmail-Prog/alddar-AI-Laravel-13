<?php

namespace Database\Seeders;

use App\Models\OrderStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::catalog() as $row) {
            Permission::query()->updateOrCreate(['slug' => $row['slug']], $row);
        }

        $this->remapRetired();
        $this->expandAdditive();

        $names = [
            'admin' => 'Admin',
            'call_center' => 'Call center',
            'dispatcher' => 'Dispatcher',
            'technician' => 'Technician',
            'accountant' => 'Accountant',
        ];

        $admin = Role::query()->updateOrCreate(['slug' => 'admin'], ['name' => $names['admin']]);
        $admin->permissions()->sync(Permission::query()->pluck('id')->all());

        foreach (Permissions::roleGrants() as $slug => $perms) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $names[$slug]]);
            $ids = Permission::query()->whereIn('slug', $perms)->pluck('id')->all();
            $role->permissions()->sync($ids);
        }

        $statuses = [
            ['slug' => 'pending', 'name_en' => 'Waiting', 'name_ar' => 'في الانتظار', 'color' => '#64748B', 'sort_order' => 1],
            ['slug' => 'on_hold', 'name_en' => 'On hold', 'name_ar' => 'معلّق', 'color' => '#D97706', 'sort_order' => 2],
            ['slug' => 'assigned', 'name_en' => 'Queued', 'name_ar' => 'في القائمة', 'color' => '#2563EB', 'sort_order' => 3],
            ['slug' => 'accepted', 'name_en' => 'Accepted', 'name_ar' => 'مقبول', 'color' => '#0D9488', 'sort_order' => 4],
            ['slug' => 'reached', 'name_en' => 'On site', 'name_ar' => 'في الموقع', 'color' => '#7C3AED', 'sort_order' => 5],
            ['slug' => 'completed', 'name_en' => 'Completed', 'name_ar' => 'مكتمل', 'color' => '#16A34A', 'sort_order' => 6],
            ['slug' => 'cancelled', 'name_en' => 'Cancelled', 'name_ar' => 'ملغى', 'color' => '#DC2626', 'sort_order' => 7],
        ];
        foreach ($statuses as $row) {
            OrderStatus::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [...$row, 'is_system' => true],
            );
        }
    }

    private function remapRetired(): void
    {
        foreach (Permissions::retired() as $oldSlug => $newSlugs) {
            $old = Permission::query()->where('slug', $oldSlug)->first();
            if (! $old) {
                continue;
            }
            $newIds = Permission::query()->whereIn('slug', $newSlugs)->pluck('id');
            $old->load(['roles', 'users']);
            foreach ($old->roles as $role) {
                $role->permissions()->syncWithoutDetaching($newIds);
            }
            foreach ($old->users as $user) {
                $user->extraPermissions()->syncWithoutDetaching($newIds);
            }
            $old->roles()->detach();
            $old->users()->detach();
            $old->delete();
        }
    }

    private function expandAdditive(): void
    {
        foreach (Permissions::additive() as $source => $extra) {
            $src = Permission::query()->where('slug', $source)->first();
            if (! $src) {
                continue;
            }
            $extraIds = Permission::query()->whereIn('slug', $extra)->pluck('id');
            $src->load(['roles', 'users']);
            foreach ($src->roles as $role) {
                $role->permissions()->syncWithoutDetaching($extraIds);
            }
            foreach ($src->users as $user) {
                $user->extraPermissions()->syncWithoutDetaching($extraIds);
            }
        }
    }
}
