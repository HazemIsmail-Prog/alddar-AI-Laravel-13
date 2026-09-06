<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function extraPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission');
    }

    public function hasRole(string $slug): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains('slug', $slug);
    }

    public function hasPermission(string $slug): bool
    {
        $this->loadMissing(['roles.permissions', 'extraPermissions']);

        if ($this->hasRole('admin')) {
            return true;
        }

        if ($this->extraPermissions->contains('slug', $slug)) {
            return true;
        }

        return $this->roles->contains(
            fn (Role $role) => $role->permissions->contains('slug', $slug)
        );
    }

    public function roleSlugs(): array
    {
        return $this->roles->pluck('slug')->values()->all();
    }

    public function permissionSlugs(): array
    {
        if ($this->hasRole('admin')) {
            return Permission::query()->pluck('slug')->values()->all();
        }

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->merge($this->extraPermissions->pluck('slug'))
            ->unique()
            ->values()
            ->all();
    }

    public function canSeeAllOrders(): bool
    {
        return $this->hasPermission('orders.dispatch') || $this->hasPermission('orders.create');
    }

    public function canSeeAllInvoices(): bool
    {
        return $this->hasPermission('invoices.confirm')
            || $this->hasPermission('orders.dispatch')
            || $this->hasPermission('orders.create')
            || $this->hasPermission('accounting.view');
    }

    public function canSeeAllStock(): bool
    {
        return $this->hasPermission('inventory.view');
    }

    public function isFieldTech(): bool
    {
        if ($this->hasRole('admin')) {
            return false;
        }

        $canWork = $this->hasPermission('orders.accept')
            || $this->hasPermission('orders.reached')
            || $this->hasPermission('orders.complete')
            || $this->hasPermission('invoices.create');

        if (! $canWork) {
            return false;
        }

        foreach ([
            'orders.create',
            'orders.dispatch',
            'clients.view',
            'contracts.view',
            'accounting.view',
            'inventory.view',
            'users.view',
        ] as $slug) {
            if ($this->hasPermission($slug)) {
                return false;
            }
        }

        return true;
    }
}
