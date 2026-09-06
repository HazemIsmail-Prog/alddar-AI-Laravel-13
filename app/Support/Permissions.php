<?php

namespace App\Support;

class Permissions
{
    /**
     * @return list<array{name: string, slug: string, group: string}>
     */
    public static function catalog(): array
    {
        return [
            ['name' => 'View clients', 'slug' => 'clients.view', 'group' => 'clients'],
            ['name' => 'Create clients', 'slug' => 'clients.create', 'group' => 'clients'],
            ['name' => 'Update clients', 'slug' => 'clients.update', 'group' => 'clients'],
            ['name' => 'Delete clients', 'slug' => 'clients.delete', 'group' => 'clients'],

            ['name' => 'View orders', 'slug' => 'orders.view', 'group' => 'orders'],
            ['name' => 'Create orders', 'slug' => 'orders.create', 'group' => 'orders'],
            ['name' => 'Update orders', 'slug' => 'orders.update', 'group' => 'orders'],
            ['name' => 'Delete orders', 'slug' => 'orders.delete', 'group' => 'orders'],
            ['name' => 'Dispatch orders', 'slug' => 'orders.dispatch', 'group' => 'orders'],
            ['name' => 'Hold orders', 'slug' => 'orders.hold', 'group' => 'orders'],
            ['name' => 'Cancel orders', 'slug' => 'orders.cancel', 'group' => 'orders'],
            ['name' => 'Accept orders', 'slug' => 'orders.accept', 'group' => 'orders'],
            ['name' => 'Mark orders reached', 'slug' => 'orders.reached', 'group' => 'orders'],
            ['name' => 'Complete orders', 'slug' => 'orders.complete', 'group' => 'orders'],

            ['name' => 'View invoices', 'slug' => 'invoices.view', 'group' => 'invoices'],
            ['name' => 'Create invoices', 'slug' => 'invoices.create', 'group' => 'invoices'],
            ['name' => 'Update invoices', 'slug' => 'invoices.update', 'group' => 'invoices'],
            ['name' => 'Confirm invoices', 'slug' => 'invoices.confirm', 'group' => 'invoices'],
            ['name' => 'Delete invoices', 'slug' => 'invoices.delete', 'group' => 'invoices'],
            ['name' => 'Collect payments', 'slug' => 'payments.collect', 'group' => 'payments'],

            ['name' => 'View contracts', 'slug' => 'contracts.view', 'group' => 'contracts'],
            ['name' => 'Create contracts', 'slug' => 'contracts.create', 'group' => 'contracts'],
            ['name' => 'Update contracts', 'slug' => 'contracts.update', 'group' => 'contracts'],

            ['name' => 'View items', 'slug' => 'items.view', 'group' => 'items'],
            ['name' => 'Create items', 'slug' => 'items.create', 'group' => 'items'],
            ['name' => 'Update items', 'slug' => 'items.update', 'group' => 'items'],
            ['name' => 'Delete items', 'slug' => 'items.delete', 'group' => 'items'],

            ['name' => 'View all stock', 'slug' => 'inventory.view', 'group' => 'inventory'],
            ['name' => 'View own van stock', 'slug' => 'inventory.view_own', 'group' => 'inventory'],
            ['name' => 'Receive stock', 'slug' => 'inventory.receive', 'group' => 'inventory'],
            ['name' => 'View inventory valuation', 'slug' => 'inventory.valuation', 'group' => 'inventory'],
            ['name' => 'Create warehouses', 'slug' => 'warehouses.create', 'group' => 'inventory'],
            ['name' => 'Update warehouses', 'slug' => 'warehouses.update', 'group' => 'inventory'],
            ['name' => 'Delete warehouses', 'slug' => 'warehouses.delete', 'group' => 'inventory'],

            ['name' => 'View transfers', 'slug' => 'transfers.view', 'group' => 'transfers'],
            ['name' => 'Create transfers', 'slug' => 'transfers.create', 'group' => 'transfers'],
            ['name' => 'Update transfers', 'slug' => 'transfers.update', 'group' => 'transfers'],
            ['name' => 'Delete transfers', 'slug' => 'transfers.delete', 'group' => 'transfers'],
            ['name' => 'Send transfers', 'slug' => 'transfers.send', 'group' => 'transfers'],
            ['name' => 'Receive transfers', 'slug' => 'transfers.receive', 'group' => 'transfers'],
            ['name' => 'Cancel transfers', 'slug' => 'transfers.cancel', 'group' => 'transfers'],

            ['name' => 'View adjustments', 'slug' => 'adjustments.view', 'group' => 'adjustments'],
            ['name' => 'Create adjustments', 'slug' => 'adjustments.create', 'group' => 'adjustments'],
            ['name' => 'Update adjustments', 'slug' => 'adjustments.update', 'group' => 'adjustments'],
            ['name' => 'Delete adjustments', 'slug' => 'adjustments.delete', 'group' => 'adjustments'],
            ['name' => 'Post adjustments', 'slug' => 'adjustments.post', 'group' => 'adjustments'],

            ['name' => 'View accounting', 'slug' => 'accounting.view', 'group' => 'accounting'],
            ['name' => 'Create journals and accounts', 'slug' => 'accounting.create', 'group' => 'accounting'],
            ['name' => 'Edit journals and accounts', 'slug' => 'accounting.update', 'group' => 'accounting'],
            ['name' => 'Delete custom accounts and manual journals', 'slug' => 'accounting.delete', 'group' => 'accounting'],

            ['name' => 'View users', 'slug' => 'users.view', 'group' => 'users'],
            ['name' => 'Create users', 'slug' => 'users.create', 'group' => 'users'],
            ['name' => 'Update users', 'slug' => 'users.update', 'group' => 'users'],
            ['name' => 'Activate or deactivate users', 'slug' => 'users.toggle_active', 'group' => 'users'],

            ['name' => 'View roles', 'slug' => 'roles.view', 'group' => 'roles'],
            ['name' => 'Create roles', 'slug' => 'roles.create', 'group' => 'roles'],
            ['name' => 'Update roles', 'slug' => 'roles.update', 'group' => 'roles'],
            ['name' => 'Delete roles', 'slug' => 'roles.delete', 'group' => 'roles'],

            ['name' => 'View permissions', 'slug' => 'permissions.view', 'group' => 'permissions'],
            ['name' => 'Create permissions', 'slug' => 'permissions.create', 'group' => 'permissions'],
            ['name' => 'Update permissions', 'slug' => 'permissions.update', 'group' => 'permissions'],
            ['name' => 'Delete permissions', 'slug' => 'permissions.delete', 'group' => 'permissions'],

            ['name' => 'View departments', 'slug' => 'departments.view', 'group' => 'departments'],
            ['name' => 'Create departments', 'slug' => 'departments.create', 'group' => 'departments'],
            ['name' => 'Update departments', 'slug' => 'departments.update', 'group' => 'departments'],
            ['name' => 'Delete departments', 'slug' => 'departments.delete', 'group' => 'departments'],

            ['name' => 'Update order statuses', 'slug' => 'statuses.update', 'group' => 'statuses'],

            ['name' => 'Dashboard: my job', 'slug' => 'dashboard.tech', 'group' => 'dashboard'],
            ['name' => 'Dashboard: orders', 'slug' => 'dashboard.orders', 'group' => 'dashboard'],
            ['name' => 'Dashboard: dispatch', 'slug' => 'dashboard.dispatch', 'group' => 'dashboard'],
            ['name' => 'Dashboard: clients', 'slug' => 'dashboard.clients', 'group' => 'dashboard'],
            ['name' => 'Dashboard: contracts', 'slug' => 'dashboard.contracts', 'group' => 'dashboard'],
            ['name' => 'Dashboard: invoices', 'slug' => 'dashboard.invoices', 'group' => 'dashboard'],
            ['name' => 'Dashboard: inventory', 'slug' => 'dashboard.inventory', 'group' => 'dashboard'],
            ['name' => 'Dashboard: accounting', 'slug' => 'dashboard.accounting', 'group' => 'dashboard'],
            ['name' => 'Dashboard: staff', 'slug' => 'dashboard.staff', 'group' => 'dashboard'],
            ['name' => 'Dashboard: activity chart', 'slug' => 'dashboard.activity', 'group' => 'dashboard'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::catalog(), 'slug');
    }

    /**
     * Retired slugs copied onto the new set, then deleted.
     *
     * @return array<string, list<string>>
     */
    public static function retired(): array
    {
        return [
            'clients.manage' => self::clientsCrud(),
            'contracts.manage' => ['contracts.view', 'contracts.create', 'contracts.update'],
            'users.manage' => [
                ...self::usersAll(),
                ...self::departmentsAll(),
                'items.create', 'items.update', 'items.delete',
                'warehouses.create', 'warehouses.update', 'warehouses.delete',
            ],
            'roles.manage' => [...self::rolesAll(), ...self::permissionsAll()],
            'orders.work' => ['orders.accept', 'orders.reached', 'orders.complete', 'invoices.create'],
            'inventory.transfer' => ['inventory.view', ...self::transfersAll()],
            'inventory.adjust' => ['inventory.receive', ...self::adjustmentsAll()],
            'statuses.manage' => ['statuses.update'],
        ];
    }

    /**
     * Extra slugs granted to anyone who already has the source slug (source is kept).
     *
     * @return array<string, list<string>>
     */
    public static function additive(): array
    {
        return [
            'orders.create' => ['orders.view', 'orders.update', 'orders.delete'],
            'contracts.create' => ['contracts.update'],
            'invoices.update' => ['invoices.delete'],
            'orders.view' => ['dashboard.orders', 'dashboard.activity'],
            'orders.dispatch' => ['dashboard.dispatch'],
            'orders.accept' => ['dashboard.tech'],
            'orders.reached' => ['dashboard.tech'],
            'orders.complete' => ['dashboard.tech'],
            'invoices.create' => ['dashboard.tech'],
            'clients.view' => ['dashboard.clients'],
            'contracts.view' => ['dashboard.contracts'],
            'invoices.view' => ['dashboard.invoices', 'dashboard.activity'],
            'payments.collect' => ['dashboard.activity'],
            'inventory.view' => ['dashboard.inventory'],
            'warehouses.create' => ['warehouses.update', 'warehouses.delete'],
            'inventory.view_own' => ['dashboard.inventory'],
            'items.view' => ['dashboard.inventory'],
            'transfers.view' => ['dashboard.inventory'],
            'adjustments.view' => ['dashboard.inventory'],
            'accounting.view' => ['dashboard.accounting'],
            'users.view' => ['dashboard.staff'],
        ];
    }

    /**
     * Canonical grants for seeded (non-admin) roles.
     *
     * @return array<string, list<string>>
     */
    public static function roleGrants(): array
    {
        return [
            'call_center' => [
                ...self::clientsCrud(),
                'orders.view', 'orders.create', 'orders.update', 'orders.delete',
                'contracts.view', 'contracts.create', 'contracts.update',
                'departments.view',
                'invoices.view',
                'dashboard.clients', 'dashboard.orders', 'dashboard.contracts',
                'dashboard.invoices', 'dashboard.activity',
            ],
            'dispatcher' => [
                ...self::clientsCrud(),
                'orders.view', 'orders.create', 'orders.update', 'orders.delete',
                'orders.dispatch', 'orders.hold', 'orders.cancel',
                'invoices.view', 'invoices.create', 'invoices.update', 'invoices.confirm', 'invoices.delete',
                'inventory.view',
                'warehouses.create', 'warehouses.update', 'warehouses.delete',
                ...self::transfersAll(),
                'statuses.update',
                'items.view',
                'contracts.view',
                'departments.view',
                'payments.collect',
                'dashboard.clients', 'dashboard.orders', 'dashboard.dispatch', 'dashboard.tech',
                'dashboard.invoices', 'dashboard.inventory', 'dashboard.contracts', 'dashboard.activity',
            ],
            'technician' => [
                'orders.view', 'orders.accept', 'orders.reached', 'orders.complete',
                'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete',
                'payments.collect',
                'items.view',
                'inventory.view_own',
                'transfers.view',
                'transfers.receive',
                'dashboard.tech',
            ],
            'accountant' => [
                'accounting.view', 'accounting.create', 'accounting.update', 'accounting.delete',
                'inventory.view',
                'warehouses.create', 'warehouses.update', 'warehouses.delete',
                'inventory.valuation',
                ...self::transfersAll(),
                'invoices.view',
                'items.view',
                'clients.view',
                'contracts.view',
                'payments.collect',
                'dashboard.accounting', 'dashboard.inventory', 'dashboard.invoices',
                'dashboard.clients', 'dashboard.contracts', 'dashboard.activity',
            ],
        ];
    }

    /** @return list<string> */
    public static function clientsCrud(): array
    {
        return ['clients.view', 'clients.create', 'clients.update', 'clients.delete'];
    }

    /** @return list<string> */
    public static function transfersAll(): array
    {
        return [
            'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
            'transfers.send', 'transfers.receive', 'transfers.cancel',
        ];
    }

    /** @return list<string> */
    public static function adjustmentsAll(): array
    {
        return [
            'adjustments.view', 'adjustments.create', 'adjustments.update',
            'adjustments.delete', 'adjustments.post',
        ];
    }

    /** @return list<string> */
    public static function usersAll(): array
    {
        return ['users.view', 'users.create', 'users.update', 'users.toggle_active'];
    }

    /** @return list<string> */
    public static function rolesAll(): array
    {
        return ['roles.view', 'roles.create', 'roles.update', 'roles.delete'];
    }

    /** @return list<string> */
    public static function permissionsAll(): array
    {
        return ['permissions.view', 'permissions.create', 'permissions.update', 'permissions.delete'];
    }

    /** @return list<string> */
    public static function departmentsAll(): array
    {
        return ['departments.view', 'departments.create', 'departments.update', 'departments.delete'];
    }
}
