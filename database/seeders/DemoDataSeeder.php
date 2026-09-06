<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::query()->get()->keyBy('slug');

        $users = [
            ['name_en' => 'Admin User', 'name_ar' => 'مستخدم الإدارة', 'civil_id' => '287010100001', 'email' => 'admin@example.test', 'role' => 'admin'],
            ['name_en' => 'Call Center', 'name_ar' => 'مركز الاتصال', 'civil_id' => '287010100002', 'email' => 'callcenter@example.test', 'role' => 'call_center'],
            ['name_en' => 'Dispatcher', 'name_ar' => 'الموزّع', 'civil_id' => '287010100003', 'email' => 'dispatcher@example.test', 'role' => 'dispatcher'],
            ['name_en' => 'Tech One', 'name_ar' => 'فنّي واحد', 'civil_id' => '287010100004', 'email' => 'tech@example.test', 'role' => 'technician'],
            ['name_en' => 'Tech Two', 'name_ar' => 'فنّي اثنان', 'civil_id' => '287010100005', 'email' => 'tech2@example.test', 'role' => 'technician'],
            ['name_en' => 'Accountant', 'name_ar' => 'المحاسب', 'civil_id' => '287010100006', 'email' => 'accountant@example.test', 'role' => 'accountant'],
        ];

        $created = [];
        foreach ($users as $row) {
            $user = User::query()->updateOrCreate(
                ['civil_id' => $row['civil_id']],
                ['name_en' => $row['name_en'], 'name_ar' => $row['name_ar'], 'email' => $row['email'], 'password' => 'password', 'is_active' => true]
            );
            $user->roles()->sync([$roles[$row['role']]->id]);
            $created[$row['role'] === 'technician' && $row['email'] === 'tech2@example.test' ? 'technician2' : $row['role']] = $user;
        }

        $maintenance = Department::query()->updateOrCreate(
            ['name_en' => 'AC Maintenance'],
            ['name_ar' => 'صيانة التكييف', 'is_service' => true],
        );
        $install = Department::query()->updateOrCreate(
            ['name_en' => 'Installation'],
            ['name_ar' => 'التركيب', 'is_service' => true],
        );
        $maintenance->users()->sync([
            $created['dispatcher']->id,
            $created['technician']->id,
            $created['technician2']->id,
        ]);
        $install->users()->sync([
            $created['dispatcher']->id,
            $created['technician']->id,
        ]);

        $central = Warehouse::query()->updateOrCreate(['name' => 'Central'], ['type' => 'central', 'technician_id' => null]);
        $van1 = Warehouse::query()->updateOrCreate(
            ['technician_id' => $created['technician']->id],
            ['name' => 'Tech van 1', 'type' => 'technician']
        );
        $van2 = Warehouse::query()->updateOrCreate(
            ['technician_id' => $created['technician2']->id],
            ['name' => 'Tech van 2', 'type' => 'technician']
        );
    }
}
