<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        $admin = User::query()->create([
            'id' => 1,
            'name_en' => 'Admin User',
            'name_ar' => 'مستخدم الإدارة',
            'civil_id' => '287010100001',
            'email' => 'admin@example.test',
            'password' => 'password',
            'is_active' => true,
            'created_by' => 1,
        ]);
        Schema::enableForeignKeyConstraints();
        Auth::login($admin);

        $this->call([
            RolePermissionSeeder::class,
            ChartOfAccountsSeeder::class,
            DemoDataSeeder::class,
        ]);

        Auth::logout();
    }
}
