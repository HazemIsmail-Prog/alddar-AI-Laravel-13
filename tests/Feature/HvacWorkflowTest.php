<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AcMachine;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Contracts\ContractService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\TransferService;
use App\Services\Invoices\InvoiceService;
use App\Services\Orders\OrderService;
use App\Services\Push\PushService;
use App\Services\Payments\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DemoClients;
use Tests\Support\DemoInventory;
use Tests\TestCase;

class HvacWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        DemoInventory::seed();
        DemoClients::seed();
    }

    public function test_dispatcher_can_login(): void
    {
        $this->post('/login', [
            'civil_id' => '287010100003',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('civil_id', '287010100003')
            ->assertJsonPath('email', 'dispatcher@example.test')
            ->assertJsonPath('name_en', 'Dispatcher')
            ->assertJsonPath('name_ar', 'الموزّع')
            ->assertJsonMissingPath('name');
    }

    public function test_me_returns_bilingual_names(): void
    {
        $user = User::query()->where('email', 'dispatcher@example.test')->first();

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('name_en', 'Dispatcher')
            ->assertJsonPath('name_ar', 'الموزّع')
            ->assertJsonMissingPath('name');
    }

    public function test_technician_is_field_tech_and_dispatcher_is_not(): void
    {
        $this->post('/login', [
            'civil_id' => '287010100004',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('email', 'tech@example.test')
            ->assertJsonPath('field_tech', true);

        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $this->actingAs($dispatcher)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('field_tech', false);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::query()->where('email', 'tech@example.test')->first();
        $user->update(['is_active' => false]);

        $this->postJson('/login', [
            'civil_id' => '287010100004',
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_creating_a_user_requires_both_names(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'email' => 'newstaff@example.test',
                'password' => 'password1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_en', 'name_ar']);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name_en' => 'New Staff',
                'email' => 'newstaff@example.test',
                'password' => 'password1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar']);
    }

    public function test_creating_a_user_returns_both_names(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name_en' => 'New Staff',
                'name_ar' => 'موظف جديد',
                'civil_id' => '287010199001',
                'email' => 'newstaff@example.test',
                'password' => 'password1',
            ])
            ->assertCreated()
            ->assertJsonPath('name_en', 'New Staff')
            ->assertJsonPath('name_ar', 'موظف جديد')
            ->assertJsonPath('civil_id', '287010199001')
            ->assertJsonMissingPath('name');
    }

    public function test_creating_a_user_requires_civil_id(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name_en' => 'New Staff',
                'name_ar' => 'موظف جديد',
                'email' => 'newstaff@example.test',
                'password' => 'password1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['civil_id']);
    }

    public function test_creating_a_user_allows_missing_email(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name_en' => 'New Staff',
                'name_ar' => 'موظف جديد',
                'civil_id' => '287010199002',
                'password' => 'password1',
            ])
            ->assertCreated()
            ->assertJsonPath('civil_id', '287010199002')
            ->assertJsonPath('email', null);
    }

    public function test_creating_a_user_rejects_duplicate_civil_id_and_email(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name_en' => 'New Staff',
                'name_ar' => 'موظف جديد',
                'civil_id' => '287010100001',
                'password' => 'password1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['civil_id']);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name_en' => 'New Staff',
                'name_ar' => 'موظف جديد',
                'civil_id' => '287010199003',
                'email' => 'admin@example.test',
                'password' => 'password1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_creating_a_department_requires_both_names(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->actingAs($admin)
            ->postJson('/api/departments', ['is_service' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_en', 'name_ar']);

        $this->actingAs($admin)
            ->postJson('/api/departments', ['name_en' => 'Plumbing'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar']);
    }

    public function test_tech_only_sees_first_sorted_order(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $current = app(OrderService::class)->currentFor($tech);
        $this->assertNotNull($current);
        $this->assertSame('assigned', $current->status);
        $this->assertTrue($current->relationLoaded('phone'));
        $this->assertNotEmpty($current->phone?->phone);
        $this->assertTrue($current->client->relationLoaded('phones'));
        $this->assertNotEmpty($current->client->phones);
        $this->assertNotEmpty($current->location->google_maps_link);

        $this->actingAs($tech)
            ->getJson('/api/tech/current')
            ->assertOk()
            ->assertJsonPath('current.id', $current->id)
            ->assertJsonPath('current.status', 'assigned')
            ->assertJsonPath('current.department_id', $current->department_id)
            ->assertJsonMissing(['current' => ['phone' => ['phone' => $current->phone->phone]]])
            ->assertJsonMissingPath('current.client')
            ->assertJsonMissingPath('current.location')
            ->assertJsonMissingPath('current.notes')
            ->assertJsonPath('department_id', $current->department_id)
            ->assertJsonPath('queue', [])
            ->assertJsonPath('currents.'.$current->department_id, $current->id);
    }

    public function test_field_tech_sees_job_details_only_after_accept(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $current = app(OrderService::class)->currentFor($tech);
        $this->assertNotNull($current);
        $this->assertSame('assigned', $current->status);

        $this->actingAs($tech)
            ->getJson('/api/tech/current')
            ->assertOk()
            ->assertJsonPath('current.id', $current->id)
            ->assertJsonPath('current.status', 'assigned')
            ->assertJsonMissingPath('current.client')
            ->assertJsonMissingPath('current.location')
            ->assertJsonMissingPath('current.phone');

        $this->actingAs($tech)->getJson("/api/order/{$current->id}/comments")->assertForbidden();

        $this->actingAs($tech)->postJson('/api/orders/'.$current->id.'/accept')->assertOk();

        $this->actingAs($tech)
            ->getJson('/api/tech/current')
            ->assertOk()
            ->assertJsonPath('current.id', $current->id)
            ->assertJsonPath('current.status', 'accepted')
            ->assertJsonPath('current.client.id', $current->client_id)
            ->assertJsonPath('current.location.google_maps_link', $current->location->google_maps_link)
            ->assertJsonPath('current.phone.phone', $current->phone->phone);

        $this->actingAs($tech)
            ->getJson('/api/orders/'.$current->id)
            ->assertOk()
            ->assertJsonPath('client.id', $current->client_id);

        $this->actingAs($tech)->getJson("/api/order/{$current->id}/comments")->assertOk();
    }

    public function test_field_tech_only_sees_current_order(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $orders = app(OrderService::class);
        $current = $orders->currentFor($tech);
        $this->assertNotNull($current);

        $pending = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($pending);
        $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$pending->id}/assign", [
                'technician_id' => $tech->id,
                'sort_order' => 99,
            ])
            ->assertOk();

        $queued = $pending->fresh();
        $this->assertFalse($orders->isCurrentJob($tech, $queued));

        $this->actingAs($tech)->getJson('/api/orders/'.$queued->id)->assertForbidden();
        $this->actingAs($tech)
            ->getJson('/api/orders/'.$current->id)
            ->assertOk()
            ->assertJsonPath('id', $current->id)
            ->assertJsonMissingPath('client')
            ->assertJsonMissingPath('location');
        $this->actingAs($tech)
            ->postJson("/api/order/{$current->id}/comments", ['body' => 'Not accepted yet'])
            ->assertForbidden();
        $this->actingAs($tech)
            ->postJson("/api/order/{$queued->id}/comments", ['body' => 'Not my current job'])
            ->assertForbidden();

        $this->actingAs($dispatcher)
            ->postJson("/api/order/{$queued->id}/comments", ['body' => 'Queued job note'])
            ->assertOk();
        $inbox = $this->actingAs($tech)->getJson('/api/inbox')->assertOk()->json();
        $this->assertFalse(collect($inbox['threads'])->contains(
            fn ($row) => ($row['type'] ?? null) === 'order' && (int) $row['id'] === (int) $queued->id
        ));
        $this->actingAs($tech)->getJson("/api/order/{$queued->id}/comments")->assertForbidden();

        $list = $this->actingAs($tech)->getJson('/api/orders')->assertOk()->json();
        $ids = collect($list)->pluck('id');
        $this->assertTrue($ids->contains($current->id));
        $this->assertFalse($ids->contains($queued->id));
    }

    public function test_field_tech_inbox_drops_held_current_job(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $orders = app(OrderService::class);
        $current = $orders->currentFor($tech);
        $this->assertNotNull($current);

        $this->actingAs($dispatcher)
            ->postJson("/api/order/{$current->id}/comments", ['body' => 'Before hold'])
            ->assertOk();

        $inbox = $this->actingAs($tech)->getJson('/api/inbox')->assertOk()->json();
        $this->assertFalse(collect($inbox['threads'])->contains(
            fn ($row) => ($row['type'] ?? null) === 'order' && (int) $row['id'] === (int) $current->id
        ));

        $this->actingAs($tech)->postJson('/api/orders/'.$current->id.'/accept')->assertOk();

        $inbox = $this->actingAs($tech)->getJson('/api/inbox')->assertOk()->json();
        $this->assertTrue(collect($inbox['threads'])->contains(
            fn ($row) => ($row['type'] ?? null) === 'order' && (int) $row['id'] === (int) $current->id
        ));
        $this->assertGreaterThan(0, (int) ($inbox['nav']['/tech'] ?? 0));

        $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$current->id}/hold", ['reason' => 'Client postponed'])
            ->assertOk();

        $inbox = $this->actingAs($tech)->getJson('/api/inbox')->assertOk()->json();
        $this->assertFalse(collect($inbox['threads'])->contains(
            fn ($row) => ($row['type'] ?? null) === 'order' && (int) $row['id'] === (int) $current->id
        ));
        $this->assertSame(
            (int) collect($inbox['threads'])->where('type', 'order')->sum('unread'),
            (int) ($inbox['nav']['/tech'] ?? 0)
        );
    }

    public function test_tech_jobs_are_separated_by_department(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $maintenance = Department::query()->where('name_en', 'AC Maintenance')->first();
        $install = Department::query()->where('name_en', 'Installation')->first();
        $this->assertTrue($tech->departments->contains('id', $maintenance->id));
        $this->assertTrue($tech->departments->contains('id', $install->id));

        $maintenanceBoard = $this->actingAs($tech)
            ->getJson('/api/tech/current?department_id='.$maintenance->id)
            ->assertOk()
            ->json();
        $this->assertSame($maintenance->id, $maintenanceBoard['department_id']);
        $this->assertNotNull($maintenanceBoard['current']);
        $this->assertSame($maintenance->id, $maintenanceBoard['current']['department_id']);
        $this->assertTrue(collect($maintenanceBoard['departments'])->contains('id', $install->id));

        $installBoard = $this->actingAs($tech)
            ->getJson('/api/tech/current?department_id='.$install->id)
            ->assertOk()
            ->json();
        $this->assertSame($install->id, $installBoard['department_id']);
        $this->assertNotNull($installBoard['current']);
        $this->assertSame($install->id, $installBoard['current']['department_id']);
        $this->assertNotEquals($maintenanceBoard['current']['id'], $installBoard['current']['id']);

        $other = Department::query()->create(['name_en' => 'Electrical', 'name_ar' => 'الكهرباء', 'is_service' => true, 'created_by' => $tech->id]);
        $this->actingAs($tech)
            ->getJson('/api/tech/current?department_id='.$other->id)
            ->assertForbidden();

        $this->actingAs($tech)
            ->postJson('/api/orders/'.$installBoard['current']['id'].'/accept')
            ->assertOk();
        $this->actingAs($tech)
            ->postJson('/api/orders/'.$maintenanceBoard['current']['id'].'/accept')
            ->assertOk();
    }

    public function test_dispatcher_hold_unassigns_technician(): void
    {
        $order = ServiceOrder::query()->where('status', 'assigned')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();

        $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$order->id}/hold", ['reason' => 'Parts delayed'])
            ->assertOk();

        $order->refresh();
        $this->assertSame('on_hold', $order->status);
        $this->assertNull($order->technician_id);
        $this->assertTrue($order->statusHistory()->where('to_status', 'on_hold')->where('reason', 'Parts delayed')->exists());
    }

    public function test_dispatcher_can_assign_with_sort_order_and_reorder(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $pending = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($pending);

        $assigned = $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$pending->id}/assign", [
                'technician_id' => $tech->id,
                'sort_order' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'assigned')
            ->assertJsonPath('sort_order', 2);

        $assignedHistory = collect($assigned->json('status_history'))
            ->firstWhere('to_status', 'assigned');
        $this->assertNotNull($assignedHistory);
        $this->assertSame($tech->id, $assignedHistory['technician_id']);
        $this->assertSame($tech->id, $assignedHistory['technician']['id']);
        $this->assertSame(
            $tech->id,
            (int) $pending->statusHistory()->where('to_status', 'assigned')->latest('id')->value('technician_id'),
        );

        $ids = ServiceOrder::query()
            ->where('technician_id', $tech->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'on_hold'])
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();
        $ids = array_values(array_unique(array_merge([$pending->id], $ids)));

        $this->actingAs($dispatcher)
            ->postJson("/api/technicians/{$tech->id}/reorder", ['order_ids' => $ids])
            ->assertOk();

        $this->assertSame(1, (int) $pending->fresh()->sort_order);
        $this->assertSame($pending->id, app(OrderService::class)->currentFor($tech)?->id);
    }

    public function test_dispatch_board_includes_held_orders(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $board = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($board['held']);
        $this->assertNotEmpty($board['departments']);
        $this->assertNotNull($board['department_id']);
        $this->assertTrue(collect($board['held'])->every(fn ($order) => $order['status'] === 'on_hold'));
        $this->assertTrue(collect($board['unassigned'])->every(fn ($order) => $order['status'] !== 'on_hold'));
        $this->assertArrayHasKey('planned', $board);
        $this->assertTrue(collect($board['planned'])->every(fn ($order) => $order['status'] !== 'on_hold'));
        $this->assertNotEmpty($board['held'][0]['hold_reason'] ?? null);
        $this->assertTrue(collect($board['departments'])->every(
            fn ($dept) => array_key_exists('completed_today', $dept) && array_key_exists('cancelled_today', $dept)
        ));
        $this->assertTrue(collect($board['technicians'])->every(
            fn ($col) => array_key_exists('completed_today', $col) && array_key_exists('cancelled_today', $col)
        ));
    }

    public function test_dispatch_board_counts_today_completed_and_cancelled(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $template = ServiceOrder::query()->first();
        $this->assertNotNull($template);

        $done = ServiceOrder::query()->create([
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $template->department_id,
            'technician_id' => $tech->id,
            'created_by' => $dispatcher->id,
            'source' => 'manual',
            'status' => 'completed',
            'planned_date' => now()->toDateString(),
            'completed_at' => now(),
            'notes' => 'Finished today',
        ]);
        $cancelled = ServiceOrder::query()->create([
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $template->department_id,
            'created_by' => $dispatcher->id,
            'source' => 'manual',
            'status' => 'cancelled',
            'planned_date' => now()->toDateString(),
            'notes' => 'Cancelled today',
        ]);
        $cancelled->statusHistory()->create([
            'user_id' => $dispatcher->id,
            'technician_id' => $tech->id,
            'from_status' => 'pending',
            'to_status' => 'assigned',
            'created_by' => $dispatcher->id,
        ]);
        $cancelled->statusHistory()->create([
            'user_id' => $dispatcher->id,
            'from_status' => 'assigned',
            'to_status' => 'cancelled',
            'reason' => 'Client cancelled',
            'created_by' => $dispatcher->id,
        ]);

        $board = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$template->department_id)
            ->assertOk()
            ->json();

        $dept = collect($board['departments'])->firstWhere('id', $template->department_id);
        $this->assertNotNull($dept);
        $this->assertGreaterThanOrEqual(1, $dept['completed_today']);
        $this->assertGreaterThanOrEqual(1, $dept['cancelled_today']);
        $techCol = collect($board['technicians'])->firstWhere('user.id', $tech->id);
        $this->assertNotNull($techCol);
        $this->assertGreaterThanOrEqual(1, $techCol['completed_today']);
        $this->assertGreaterThanOrEqual(1, $techCol['cancelled_today']);
        $today = now()->toDateString();
        $listed = $this->actingAs($dispatcher)
            ->getJson('/api/orders?status=cancelled&department_id='.$template->department_id.'&technician_id='.$tech->id.'&cancelled_from='.$today.'&cancelled_to='.$today)
            ->assertOk()
            ->json();
        $this->assertTrue(collect($listed)->contains(fn ($row) => (int) $row['id'] === (int) $cancelled->id));
        $this->assertFalse(collect($board['unassigned'])->pluck('id')->contains($done->id));
        $this->assertFalse(collect($board['unassigned'])->pluck('id')->contains($cancelled->id));
    }

    public function test_dispatcher_can_reorder_unassigned_and_held_queues(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $template = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($template);

        $extraPending = ServiceOrder::query()->create([
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $template->department_id,
            'created_by' => $dispatcher->id,
            'source' => 'manual',
            'status' => 'pending',
            'notes' => 'Second waiting job',
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/api/dispatch/reorder', [
                'queue' => 'unassigned',
                'order_ids' => [$extraPending->id, $template->id],
            ])
            ->assertOk();

        $this->assertSame(1, (int) $extraPending->fresh()->sort_order);
        $this->assertSame(2, (int) $template->fresh()->sort_order);

        $held = ServiceOrder::query()->where('status', 'on_hold')->first();
        $this->assertNotNull($held);
        $extraHeld = ServiceOrder::query()->create([
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $template->department_id,
            'created_by' => $dispatcher->id,
            'source' => 'manual',
            'status' => 'pending',
            'notes' => 'Will hold',
        ]);
        app(OrderService::class)->hold($extraHeld, $dispatcher, 'Waiting on parts', app(InvoiceService::class));

        $this->actingAs($dispatcher)
            ->postJson('/api/dispatch/reorder', [
                'queue' => 'held',
                'order_ids' => [$extraHeld->id, $held->id],
            ])
            ->assertOk();

        $this->assertSame(1, (int) $extraHeld->fresh()->sort_order);
        $this->assertSame(2, (int) $held->fresh()->sort_order);
        $this->assertSame('on_hold', $extraHeld->fresh()->status);
    }

    public function test_dispatch_board_is_scoped_to_a_service_department(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $maintenance = Department::query()->where('name_en', 'AC Maintenance')->first();
        $install = Department::query()->where('name_en', 'Installation')->first();

        $maintenanceBoard = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$maintenance->id)
            ->assertOk()
            ->json();

        $this->assertSame($maintenance->id, $maintenanceBoard['department_id']);
        $this->assertNotEmpty($maintenanceBoard['technicians']);
        $this->assertTrue(collect($maintenanceBoard['unassigned'])->every(
            fn ($order) => $order['department_id'] === $maintenance->id
        ));
        $this->assertTrue(collect($maintenanceBoard['held'])->every(
            fn ($order) => $order['department_id'] === $maintenance->id
        ));
        $techIds = collect($maintenanceBoard['technicians'])->pluck('user.id');
        $this->assertSame(
            $techIds->count(),
            User::query()
                ->whereIn('id', $techIds)
                ->whereHas('departments', fn ($q) => $q->where('departments.id', $maintenance->id))
                ->count()
        );

        $installBoard = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$install->id)
            ->assertOk()
            ->json();
        $this->assertSame($install->id, $installBoard['department_id']);
        $techOne = User::query()->where('email', 'tech@example.test')->first();
        $techTwo = User::query()->where('email', 'tech2@example.test')->first();
        $installTechIds = collect($installBoard['technicians'])->pluck('user.id');
        $this->assertTrue($installTechIds->contains($techOne->id));
        $this->assertFalse($installTechIds->contains($techTwo->id));
        $this->assertTrue(collect($installBoard['unassigned'])->isEmpty());
        $this->assertTrue(collect($installBoard['held'])->isEmpty());
        $this->assertTrue(collect($installBoard['technicians'])->every(
            fn ($col) => collect($col['orders'])->every(fn ($order) => $order['department_id'] === $install->id)
        ));

        $other = Department::query()->create(['name_en' => 'Electrical', 'name_ar' => 'الكهرباء', 'is_service' => true, 'created_by' => $admin->id]);
        $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$other->id)
            ->assertForbidden();
        $this->actingAs($admin)
            ->getJson('/api/dispatch/board?department_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('department_id', $other->id);

        $template = ServiceOrder::query()->first();
        $foreign = ServiceOrder::query()->create([
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $other->id,
            'created_by' => $admin->id,
            'source' => 'manual',
            'status' => 'pending',
            'notes' => 'Other department job',
        ]);
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $this->actingAs($dispatcher)
            ->postJson('/api/orders/'.$foreign->id.'/assign', ['technician_id' => $tech->id])
            ->assertForbidden();
    }

    public function test_cannot_create_order_for_non_service_department(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $hr = Department::query()->create(['name_en' => 'HR', 'name_ar' => 'الموارد البشرية', 'is_service' => false, 'created_by' => $dispatcher->id]);
        $order = ServiceOrder::query()->first();

        $this->actingAs($dispatcher)
            ->postJson('/api/orders', [
                'client_id' => $order->client_id,
                'phone_id' => $order->phone_id,
                'location_id' => $order->location_id,
                'department_id' => $hr->id,
                'notes' => 'Should fail',
            ])
            ->assertStatus(422);

        $admin = User::query()->where('email', 'admin@example.test')->first();
        $this->actingAs($admin)
            ->postJson('/api/items', [
                'sku' => 'HR-ONLY',
                'name' => 'HR item',
                'type' => 'tracked_part',
                'valuation_method' => 'fifo',
                'cost' => 1,
                'default_price' => 2,
                'department_id' => $hr->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_turn_off_service_flag_while_department_has_orders(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $used = Department::query()->has('orders')->first();
        $this->assertNotNull($used);
        $this->actingAs($admin)
            ->putJson('/api/departments/'.$used->id, ['is_service' => false])
            ->assertStatus(422);

        $empty = Department::query()->create(['name_en' => 'Spare crew', 'name_ar' => 'طاقم احتياطي', 'is_service' => true, 'created_by' => $admin->id]);
        $this->actingAs($admin)
            ->putJson('/api/departments/'.$empty->id, ['is_service' => false])
            ->assertOk()
            ->assertJsonPath('is_service', false);
    }

    public function test_cannot_complete_without_invoice(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with('warehouse')->first();
        $order = app(OrderService::class)->currentFor($tech);
        $orders = app(OrderService::class);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $this->expectException(\InvalidArgumentException::class);
        $orders->complete($order->fresh(), $tech);
    }

    public function test_order_can_have_multiple_confirmed_invoices(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $this->assertNotNull($order);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $service = Item::query()->where('sku', 'SVC-VISIT')->first();
        $invoices = app(InvoiceService::class);
        $first = $invoices->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
        ]);
        $invoices->confirm($first, $dispatcher);

        $this->actingAs($tech)
            ->postJson('/api/orders/'.$order->id.'/invoice', [
                'items' => [['item_id' => $service->id, 'quantity' => 1]],
                'report' => 'Follow-up work on the same visit.',
            ])
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('status', 'draft');

        $this->actingAs($tech)
            ->postJson('/api/orders/'.$order->id.'/invoice', [
                'items' => [['item_id' => $service->id, 'quantity' => 1]],
                'report' => 'Should not create a second draft.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Order already has a draft invoice.');

        $second = $order->invoices()->where('status', 'draft')->first();
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $invoices->confirm($second, $dispatcher);
        $this->assertSame(2, $order->invoices()->where('status', 'confirmed')->count());
    }

    public function test_invoice_can_be_deleted(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $this->assertNotNull($order);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $service = Item::query()->where('sku', 'SVC-VISIT')->first();
        $filter = Item::query()->where('sku', 'FLT-20')->first();
        $invoices = app(InvoiceService::class);
        $qtyBefore = (string) $tech->warehouse->stockLevels()->where('item_id', $filter->id)->value('qty');

        $draft = $invoices->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
            ['item_id' => $filter->id, 'quantity' => 1],
        ]);
        $this->assertNotSame($qtyBefore, (string) $tech->warehouse->stockLevels()->where('item_id', $filter->id)->value('qty'));

        $this->actingAs($callCenter)->deleteJson('/api/invoices/'.$draft->id)->assertForbidden();

        $this->actingAs($tech)->deleteJson('/api/invoices/'.$draft->id)->assertOk();
        $this->assertNull(Invoice::query()->find($draft->id));
        $this->assertSame($qtyBefore, (string) $tech->warehouse->stockLevels()->where('item_id', $filter->id)->value('qty'));

        $first = $invoices->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
        ]);
        $invoices->confirm($first, $dispatcher);

        $this->actingAs($dispatcher)->deleteJson('/api/invoices/'.$first->id)->assertOk();
        $this->assertNotNull(
            JournalEntry::query()
                ->where('source_type', 'invoice')
                ->where('source_id', $first->id)
                ->where('event_key', 'delete')
                ->first()
        );

        $second = $invoices->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
        ]);
        $invoices->confirm($second, $dispatcher);
        PaymentAllocation::query()->create([
            'client_id' => $order->client_id,
            'invoice_id' => $second->id,
            'applied_by' => $dispatcher->id,
            'created_by' => $dispatcher->id,
            'amount' => 10,
        ]);
        $this->actingAs($dispatcher)
            ->deleteJson('/api/invoices/'.$second->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete an invoice that has payments.');

        $second->allocations()->delete();
        $orders->complete($order->fresh(), $tech);
        $this->actingAs($dispatcher)
            ->deleteJson('/api/invoices/'.$second->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete the last invoice on a completed order.');
    }

    public function test_tracked_qty_cannot_exceed_warehouse_stock(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $this->actingAs($admin);
        $tech = User::query()->where('email', 'tech@example.test')->with('warehouse')->first();
        $item = Item::query()->where('sku', 'FLT-20')->first();
        $this->expectException(\InvalidArgumentException::class);
        app(InventoryService::class)->issue($tech->warehouse, $item, 999, 'test');
    }

    public function test_fifo_issues_oldest_layer_first(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $this->actingAs($admin);
        $central = Warehouse::query()->where('name', 'Central')->first();
        $item = Item::query()->where('sku', 'FLT-20')->first();
        $inventory = app(InventoryService::class);
        $inventory->receive($central, $item, 2, 20, 'test');
        $result = $inventory->issue($central, $item, 1, 'test');
        $this->assertSame(8, $result['unit_cost']);
    }

    public function test_transfer_send_receive_and_valuation_balanced_journal(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $this->actingAs($admin);
        $draft = WarehouseTransfer::query()->where('status', 'draft')->first();
        $transfers = app(TransferService::class);
        $transfers->send($draft, $admin);
        $this->assertSame('in_transit', $draft->fresh()->status);
        $transfers->receive($draft->fresh(), $admin);
        $this->assertSame('completed', $draft->fresh()->status);

        $entry = JournalEntry::query()->where('source_type', 'warehouse_transfer')->where('event_key', 'send')->first();
        $this->assertNotNull($entry);
        $this->assertEquals($entry->lines()->sum('debit'), $entry->lines()->sum('credit'));
    }

    public function test_warranty_contract_value_is_zero(): void
    {
        $contract = Contract::query()->where('type', 'warranty')->first();
        $this->assertNotNull($contract);
        $this->assertSame(0, $contract->total_amount);
        $this->assertTrue($contract->includes_compressor_warranty);
        $this->assertTrue($contract->includes_spare_parts);
        $this->assertSame(0, $contract->installments()->count());
    }

    public function test_invoice_confirm_posts_balanced_journal(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $this->assertNotNull($order);
        $this->assertNull($order->contract_id);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $service = Item::query()->where('sku', 'SVC-VISIT')->first();
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
        ]);
        $invoices->confirm($invoice, $dispatcher);

        $journal = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->first();
        $this->assertNotNull($journal);
        $this->assertEquals($journal->lines()->sum('debit'), $journal->lines()->sum('credit'));
    }

    public function test_accountant_can_create_and_edit_manual_journals_only(): void
    {
        $accountant = User::query()->where('email', 'accountant@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $cash = Account::query()->where('code', '1000')->first();
        $ar = Account::query()->where('code', '1100')->first();
        $this->assertNotNull($cash);
        $this->assertNotNull($ar);

        $this->actingAs($tech)
            ->postJson('/api/journals', [
                'date' => now()->toDateString(),
                'description' => 'Office cash',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 50, 'credit' => 0],
                    ['account_id' => $ar->id, 'debit' => 0, 'credit' => 50],
                ],
            ])
            ->assertForbidden();

        $this->actingAs($accountant)
            ->postJson('/api/journals', [
                'date' => now()->toDateString(),
                'description' => 'Office cash',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 50, 'credit' => 0],
                    ['account_id' => $ar->id, 'debit' => 0, 'credit' => 40],
                ],
            ])
            ->assertUnprocessable();

        $created = $this->actingAs($accountant)
            ->postJson('/api/journals', [
                'date' => now()->toDateString(),
                'description' => 'Office cash',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 50, 'credit' => 0],
                    ['account_id' => $ar->id, 'debit' => 0, 'credit' => 50],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('source_type', 'manual')
            ->assertJsonPath('is_manual', true)
            ->assertJsonPath('description', 'Office cash')
            ->json();

        $this->actingAs($accountant)
            ->putJson('/api/journals/'.$created['id'], [
                'date' => now()->toDateString(),
                'description' => 'Petty cash',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 25, 'credit' => 0],
                    ['account_id' => $ar->id, 'debit' => 0, 'credit' => 25],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('description', 'Petty cash')
            ->assertJsonPath('lines.0.debit', 25);

        $system = JournalEntry::query()->where('source_type', '!=', 'manual')->first();
        $this->assertNotNull($system);
        $this->actingAs($accountant)
            ->putJson('/api/journals/'.$system->id, [
                'date' => now()->toDateString(),
                'description' => 'Should fail',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 10, 'credit' => 0],
                    ['account_id' => $ar->id, 'debit' => 0, 'credit' => 10],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only manual journals can be edited.');

        $this->actingAs($accountant)
            ->deleteJson('/api/journals/'.$system->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only manual journals can be deleted.');

        $this->actingAs($tech)
            ->deleteJson('/api/journals/'.$created['id'])
            ->assertForbidden();

        $this->actingAs($accountant)
            ->deleteJson('/api/journals/'.$created['id'])
            ->assertOk();
        $this->assertDatabaseMissing('journal_entries', ['id' => $created['id']]);
        $this->assertDatabaseMissing('journal_lines', ['journal_id' => $created['id']]);
    }

    public function test_accountant_can_run_core_accounting_reports(): void
    {
        $accountant = User::query()->where('email', 'accountant@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $cash = Account::query()->where('code', '1000')->first();
        $client = Client::query()->first();
        $this->assertNotNull($cash);
        $this->assertNotNull($client);

        $this->actingAs($tech)->getJson('/api/reports/income-statement')->assertForbidden();

        $pnl = $this->actingAs($accountant)
            ->getJson('/api/reports/income-statement')
            ->assertOk()
            ->assertJsonStructure(['income', 'expenses', 'income_total', 'expense_total', 'net_income'])
            ->json();
        $this->assertSame($pnl['income_total'] - $pnl['expense_total'], $pnl['net_income']);

        $sheet = $this->actingAs($accountant)
            ->getJson('/api/reports/balance-sheet')
            ->assertOk()
            ->json();
        $this->assertSame($sheet['asset_total'], $sheet['liabilities_and_equity']);

        $this->actingAs($accountant)
            ->getJson('/api/trial-balance?to='.now()->toDateString())
            ->assertOk()
            ->assertJsonFragment(['code' => '1000']);

        $this->actingAs($accountant)
            ->getJson('/api/accounts/'.$cash->id.'/ledger')
            ->assertOk()
            ->assertJsonStructure(['account', 'opening', 'closing', 'lines']);

        $this->actingAs($accountant)
            ->getJson('/api/reports/ar-aging')
            ->assertOk()
            ->assertJsonStructure(['buckets', 'total', 'rows']);

        $this->actingAs($accountant)
            ->getJson('/api/reports/collections')
            ->assertOk()
            ->assertJsonStructure(['total', 'by_method', 'rows']);

        $this->actingAs($accountant)
            ->getJson('/api/reports/client-statement')
            ->assertUnprocessable();

        $this->actingAs($accountant)
            ->getJson('/api/reports/client-statement?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonStructure(['opening', 'owed', 'wallet', 'net_due', 'lines']);

        $this->actingAs($accountant)
            ->getJson('/api/reports/installments')
            ->assertOk()
            ->assertJsonStructure(['rows', 'total_remaining']);

        $this->actingAs($accountant)
            ->getJson('/api/journals?source=manual')
            ->assertOk();
        $this->actingAs($accountant)
            ->getJson('/api/trial-balance?from=2099-01-01&to=2099-01-02')
            ->assertOk()
            ->assertJsonFragment(['code' => '1000', 'debit' => 0, 'credit' => 0]);
    }

    public function test_accountant_can_manage_custom_accounts_but_system_accounts_stay_locked(): void
    {
        $accountant = User::query()->where('email', 'accountant@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $cash = Account::query()->where('code', '1000')->first();
        $ar = Account::query()->where('code', '1100')->first();
        $this->assertNotNull($cash);
        $this->assertNotNull($ar);
        $this->assertTrue($cash->is_system);

        $this->actingAs($accountant)
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonFragment(['code' => '1000', 'is_system' => true]);

        $this->actingAs($tech)
            ->postJson('/api/accounts', [
                'code' => '1300',
                'name' => 'Petty cash',
                'type' => 'asset',
            ])
            ->assertForbidden();

        $created = $this->actingAs($accountant)
            ->postJson('/api/accounts', [
                'code' => '1300',
                'name' => 'Petty cash',
                'type' => 'asset',
            ])
            ->assertOk()
            ->assertJsonPath('code', '1300')
            ->assertJsonPath('name', 'Petty cash')
            ->assertJsonPath('type', 'asset')
            ->assertJsonPath('is_system', false)
            ->json();

        $this->actingAs($accountant)
            ->putJson('/api/accounts/'.$cash->id, [
                'code' => '1000',
                'name' => 'Office cash',
                'type' => 'asset',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Office cash')
            ->assertJsonPath('code', '1000')
            ->assertJsonPath('type', 'asset')
            ->assertJsonPath('is_system', true);

        $this->actingAs($accountant)
            ->putJson('/api/accounts/'.$cash->id, [
                'code' => '1999',
                'name' => 'Office cash',
                'type' => 'expense',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'System accounts can only be renamed.');

        $this->actingAs($accountant)
            ->deleteJson('/api/accounts/'.$cash->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'System accounts cannot be deleted.');

        $this->actingAs($accountant)
            ->putJson('/api/accounts/'.$created['id'], [
                'code' => '1310',
                'name' => 'Drawer cash',
                'type' => 'asset',
            ])
            ->assertOk()
            ->assertJsonPath('code', '1310')
            ->assertJsonPath('name', 'Drawer cash');

        $this->actingAs($accountant)
            ->postJson('/api/journals', [
                'date' => now()->toDateString(),
                'description' => 'Use custom account',
                'lines' => [
                    ['account_id' => $created['id'], 'debit' => 10, 'credit' => 0],
                    ['account_id' => $ar->id, 'debit' => 0, 'credit' => 10],
                ],
            ])
            ->assertOk();

        $this->actingAs($accountant)
            ->deleteJson('/api/accounts/'.$created['id'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This account has journal lines and cannot be deleted.');

        $unused = $this->actingAs($accountant)
            ->postJson('/api/accounts', [
                'code' => '6100',
                'name' => 'Office rent',
                'type' => 'expense',
            ])
            ->assertOk()
            ->json();

        $this->actingAs($accountant)
            ->deleteJson('/api/accounts/'.$unused['id'])
            ->assertOk();
        $this->assertDatabaseMissing('accounts', ['id' => $unused['id']]);

        $this->actingAs($tech)
            ->deleteJson('/api/accounts/'.$created['id'])
            ->assertForbidden();
    }

    public function test_clients_are_paginated_and_searchable(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $this->actingAs($user);
        for ($n = Client::query()->count(); $n < 110; $n++) {
            Client::query()->create(['name' => 'Paged Client '.$n, 'created_by' => $user->id]);
        }

        $page = $this->actingAs($user)
            ->getJson('/api/clients?per_page=20')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page', 'current_page', 'last_page']);
        $this->assertGreaterThan(100, $page->json('total'));
        $this->assertGreaterThan(1, $page->json('last_page'));
        $this->assertCount(20, $page->json('data'));

        $this->actingAs($user)
            ->getJson('/api/clients?page=2&per_page=20')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(20, 'data');

        $this->actingAs($user)
            ->getJson('/api/clients?search=555&compact=1&per_page=8')
            ->assertOk()
            ->assertJsonPath('per_page', 8);

        $this->actingAs($user)
            ->getJson('/api/clients?search=Mishref&per_page=8')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sara Ahmed');
    }

    public function test_clients_index_supports_filters(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();

        $byName = $this->actingAs($user)
            ->getJson('/api/clients?name=Sara&per_page=8')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($byName);
        $this->assertTrue(collect($byName)->every(fn ($row) => str_contains($row['name'], 'Sara')));

        $byPhone = $this->actingAs($user)
            ->getJson('/api/clients?phone=5550101&per_page=8')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($byPhone);
    }

    public function test_clients_index_includes_due_balance(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $office = Client::query()->where('name', 'Nile Offices LLC')->first();
        $this->assertNotNull($office);

        $row = collect(
            $this->actingAs($user)->getJson('/api/clients?name=Nile&per_page=8')->assertOk()->json('data')
        )->firstWhere('id', $office->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('net_due', $row);
        $this->assertArrayHasKey('outstanding', $row);
        $this->assertArrayHasKey('wallet', $row);

        $summary = $this->actingAs($admin)->getJson("/api/clients/{$office->id}/summary")->assertOk()->json('totals');
        $this->assertSame($summary['net_due'], $row['net_due']);
        $this->assertSame($summary['outstanding'], $row['outstanding']);
        $this->assertSame($summary['wallet'], $row['wallet']);

        $futureRemaining = (int) ContractInstallment::query()
            ->whereHas('contract', fn ($q) => $q->where('client_id', $office->id))
            ->whereDate('due_date', '>', now())
            ->where('status', '!=', 'paid')
            ->withSum('allocations', 'amount')
            ->get()
            ->sum(fn ($installment) => max(0, (int) $installment->amount - (int) ($installment->allocations_sum_amount ?? 0)));
        $this->assertGreaterThan(0, $futureRemaining);
        $this->assertSame($row['outstanding'] - $futureRemaining, $row['net_due'] + $row['wallet']);
    }

    public function test_orders_and_contracts_show_their_own_net_due(): void
    {
        $user = User::query()->where('email', 'admin@example.test')->first();
        $office = Client::query()->where('name', 'Nile Offices LLC')->first();
        $this->assertNotNull($office);

        $clientDue = collect(
            $this->actingAs($user)->getJson('/api/clients?name=Nile&per_page=8')->assertOk()->json('data')
        )->firstWhere('id', $office->id)['net_due'];

        $annual = Contract::query()->where('type', 'annual')->first();
        $this->assertNotNull($annual);
        $expectedContractDue = (int) ContractInstallment::query()
            ->where('contract_id', $annual->id)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', now())
            ->withSum('allocations', 'amount')
            ->get()
            ->sum(fn ($row) => max(0, (int) $row->amount - (int) ($row->allocations_sum_amount ?? 0)));
        $this->assertGreaterThan(0, $expectedContractDue);

        $contract = collect($this->actingAs($user)->getJson('/api/contracts?type[]=annual')->assertOk()->json())
            ->firstWhere('id', $annual->id);
        $this->assertSame($expectedContractDue, $contract['net_due']);

        $warranty = Contract::query()->where('type', 'warranty')->first();
        $this->assertNotNull($warranty);
        $warrantyRow = collect($this->actingAs($user)->getJson('/api/contracts?type[]=warranty')->assertOk()->json())
            ->firstWhere('id', $warranty->id);
        $this->assertSame(0, $warrantyRow['net_due']);

        $openOrder = ServiceOrder::query()->whereDoesntHave('invoice')->first();
        $this->assertNotNull($openOrder);
        $orderRow = collect($this->actingAs($user)->getJson('/api/orders?number='.$openOrder->id)->assertOk()->json())->first();
        $this->assertSame(0, $orderRow['net_due']);

        $invoiced = ServiceOrder::query()->whereHas('invoice', fn ($q) => $q->where('status', 'confirmed'))->first();
        if ($invoiced) {
            $invoice = $invoiced->invoice()->first();
            $expectedOrderDue = app(PaymentService::class)->invoiceRemaining($invoice);
            $invoicedRow = collect($this->actingAs($user)->getJson('/api/orders?number='.$invoiced->id)->assertOk()->json())->first();
            $this->assertSame($expectedOrderDue, $invoicedRow['net_due']);
        }

        $this->assertNotSame($clientDue, $warrantyRow['net_due']);
    }

    public function test_client_summary_includes_related_activity(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $client = Client::query()->where('name', 'Nile Offices LLC')->first();
        $this->assertNotNull($client);

        $this->actingAs($tech)
            ->getJson("/api/clients/{$client->id}/summary")
            ->assertForbidden();

        $summary = $this->actingAs($admin)
            ->getJson("/api/clients/{$client->id}/summary")
            ->assertOk()
            ->assertJsonPath('name', 'Nile Offices LLC')
            ->assertJsonStructure([
                'phones',
                'locations',
                'contracts',
                'orders',
                'invoices',
                'installments',
                'payments',
                'totals' => [
                    'locations',
                    'machines',
                    'contracts',
                    'orders',
                    'collected',
                    'outstanding',
                    'wallet',
                    'net_due',
                ],
            ])
            ->json();

        $this->assertGreaterThan(0, $summary['totals']['contracts']);
        $this->assertGreaterThan(0, $summary['totals']['orders']);
        $this->assertGreaterThan(0, $summary['totals']['locations']);

        $this->actingAs($admin)
            ->getJson('/api/clients/999999/summary')
            ->assertNotFound();
    }

    public function test_technician_can_remove_draft_invoice_line(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $service = Item::query()->where('sku', 'SVC-VISIT')->first();
        $filter = Item::query()->where('sku', 'FLT-20')->first();
        $invoice = app(InvoiceService::class)->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
            ['item_id' => $filter->id, 'quantity' => 1],
        ]);

        $this->actingAs($tech)
            ->putJson("/api/invoices/{$invoice->id}", [
                'items' => [['item_id' => $service->id, 'quantity' => 1]],
                'report' => "Replaced the filter.\nSystem cooling normally.",
            ])
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('report', "Replaced the filter.\nSystem cooling normally.");
    }

    public function test_technician_invoice_requires_a_report(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);
        $service = Item::query()->where('sku', 'SVC-VISIT')->first();

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['item_id' => $service->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable();

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['item_id' => $service->id, 'quantity' => 1]],
                'report' => "Cleaned condenser.\nNo leaks found.",
            ])
            ->assertOk()
            ->assertJsonPath('report', "Cleaned condenser.\nNo leaks found.");
    }

    public function test_client_phone_can_be_updated_and_deleted(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $client = Client::query()->first();
        $phone = $client->phones()->first();
        $spare = $client->phones()->create([
            'country_code' => '+965',
            'phone' => '555-7777',
            'is_primary' => false,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->putJson("/api/phones/{$phone->id}", ['phone' => '555-9999'])
            ->assertOk()
            ->assertJsonPath('phone', '555-9999')
            ->assertJsonPath('country_code', '+965');

        $this->actingAs($user)
            ->deleteJson("/api/phones/{$phone->id}")
            ->assertUnprocessable();

        $this->actingAs($user)
            ->deleteJson("/api/phones/{$spare->id}")
            ->assertOk();
    }

    public function test_client_can_be_created_with_phones_locations_and_machines(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();

        $this->actingAs($user)
            ->postJson('/api/clients', [
                'name' => 'New Co',
                'notes' => 'VIP',
                'phones' => [['country_code' => '+971', 'phone' => '555-1111', 'is_primary' => true]],
                'locations' => [[
                    'label' => 'Home',
                    'country' => 'Kuwait',
                    'city' => 'Kuwait City',
                    'area' => 'Salmiya',
                    'block' => '4',
                    'street' => '12',
                    'avenue' => '3',
                    'building' => '8',
                    'floor' => '2',
                    'flat' => '5',
                    'paci_number' => 28450123,
                    'google_maps_link' => 'https://maps.google.com/?q=29.2776,48.0698',
                    'machines' => [['brand' => 'Carrier', 'model' => 'X', 'serial' => 'S1']],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'New Co')
            ->assertJsonPath('phones.0.phone', '555-1111')
            ->assertJsonPath('phones.0.country_code', '+971')
            ->assertJsonPath('phones.0.full_phone', '+971 555-1111')
            ->assertJsonPath('locations.0.area', 'Salmiya')
            ->assertJsonPath('locations.0.block', '4')
            ->assertJsonPath('locations.0.street', '12')
            ->assertJsonPath('locations.0.paci_number', 28450123)
            ->assertJsonPath('locations.0.google_maps_link', 'https://maps.google.com/?q=29.2776,48.0698')
            ->assertJsonPath('locations.0.machines.0.serial', 'S1');
    }

    public function test_order_requires_a_client_phone(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $order = ServiceOrder::query()->where('status', 'pending')->first();
        $this->assertNotNull($order);

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $order->client_id,
                'location_id' => $order->location_id,
                'department_id' => $order->department_id,
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $order->client_id,
                'phone_id' => $order->phone_id,
                'location_id' => $order->location_id,
                'department_id' => $order->department_id,
                'notes' => 'Call this number',
            ])
            ->assertCreated()
            ->assertJsonPath('phone_id', $order->phone_id)
            ->assertJsonPath('phone.id', $order->phone_id);
    }

    public function test_new_unassigned_orders_appear_at_the_top_of_the_dispatch_queue(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $template = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($template);

        $payload = [
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $template->department_id,
        ];

        $first = $this->actingAs($user)->postJson('/api/orders', $payload)->assertCreated()->json();
        $second = $this->actingAs($user)->postJson('/api/orders', $payload)->assertCreated()->json();

        $board = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$template->department_id)
            ->assertOk()
            ->json();

        $unassignedIds = collect($board['unassigned'])->pluck('id')->all();
        $this->assertSame([$second['id'], $first['id']], array_slice($unassignedIds, 0, 2));
        $this->assertSame(1, (int) ServiceOrder::query()->find($second['id'])->sort_order);
        $this->assertSame(2, (int) ServiceOrder::query()->find($first['id'])->sort_order);
    }

    public function test_manual_order_planned_date_defaults_to_today_and_future_jobs_use_planned_queue(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $techTwo = User::query()->where('email', 'tech2@example.test')->first();
        $template = ServiceOrder::query()->where('status', 'pending')->first();
        $this->assertNotNull($template);
        $departmentId = $template->department_id;
        $today = now()->toDateString();
        $future = now()->addDays(5)->toDateString();
        $past = now()->subDay()->toDateString();

        $created = $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $template->client_id,
                'phone_id' => $template->phone_id,
                'location_id' => $template->location_id,
                'department_id' => $departmentId,
            ])
            ->assertCreated()
            ->json();
        $this->assertSame($today, substr((string) $created['planned_date'], 0, 10));

        $futureOrder = $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $template->client_id,
                'phone_id' => $template->phone_id,
                'location_id' => $template->location_id,
                'department_id' => $departmentId,
                'planned_date' => $future,
                'notes' => 'Later visit',
            ])
            ->assertCreated()
            ->json();

        $pastOrder = $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $template->client_id,
                'phone_id' => $template->phone_id,
                'location_id' => $template->location_id,
                'department_id' => $departmentId,
                'planned_date' => $past,
                'notes' => 'Overdue visit',
            ])
            ->assertCreated()
            ->json();

        $board = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$departmentId)
            ->assertOk()
            ->json();

        $unassignedIds = collect($board['unassigned'])->pluck('id');
        $plannedIds = collect($board['planned'])->pluck('id');
        $this->assertTrue($unassignedIds->contains($created['id']));
        $this->assertTrue($unassignedIds->contains($pastOrder['id']));
        $this->assertFalse($plannedIds->contains($created['id']));
        $this->assertFalse($plannedIds->contains($pastOrder['id']));
        $this->assertTrue($plannedIds->contains($futureOrder['id']));
        $this->assertFalse($unassignedIds->contains($futureOrder['id']));

        $this->actingAs($dispatcher)
            ->postJson('/api/dispatch/reorder', [
                'queue' => 'planned',
                'order_ids' => [$futureOrder['id']],
            ])
            ->assertOk();
        $this->assertSame(1, (int) ServiceOrder::query()->find($futureOrder['id'])->sort_order);

        $this->actingAs($dispatcher)
            ->postJson('/api/orders/'.$futureOrder['id'].'/assign', ['technician_id' => $tech->id])
            ->assertOk();

        $assignedBoard = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$departmentId)
            ->assertOk()
            ->json();
        $this->assertFalse(collect($assignedBoard['planned'])->pluck('id')->contains($futureOrder['id']));
        $techOrders = collect($assignedBoard['technicians'])->firstWhere('user.id', $tech->id)['orders'] ?? [];
        $this->assertTrue(collect($techOrders)->pluck('id')->contains($futureOrder['id']));

        ServiceOrder::query()->where('id', $futureOrder['id'])->update(['sort_order' => 1]);
        $todayCurrent = app(OrderService::class)->currentFor($tech, $departmentId);
        $this->assertNotNull($todayCurrent);
        $this->assertNotSame($futureOrder['id'], $todayCurrent->id);
        $this->assertFalse($todayCurrent->isPlannedFuture());

        $this->actingAs($dispatcher)
            ->postJson('/api/orders/'.$created['id'].'/assign', ['technician_id' => $techTwo->id])
            ->assertOk();
        $onlyFuture = $this->actingAs($user)
            ->postJson('/api/orders', [
                'client_id' => $template->client_id,
                'phone_id' => $template->phone_id,
                'location_id' => $template->location_id,
                'department_id' => $departmentId,
                'planned_date' => $future,
                'notes' => 'Tech two later job',
            ])
            ->assertCreated()
            ->json();
        $this->actingAs($dispatcher)
            ->postJson('/api/orders/'.$onlyFuture['id'].'/assign', ['technician_id' => $techTwo->id, 'sort_order' => 1])
            ->assertOk();
        ServiceOrder::query()->where('id', $created['id'])->update(['sort_order' => 2]);
        $this->assertSame($created['id'], app(OrderService::class)->currentFor($techTwo, $departmentId)?->id);

        ServiceOrder::query()->where('id', $created['id'])->update(['status' => 'completed']);
        $this->assertNull(app(OrderService::class)->currentFor($techTwo, $departmentId));
    }

    public function test_updating_planned_date_moves_order_between_unassigned_and_planned(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $template = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($template);

        $order = ServiceOrder::query()->create([
            'client_id' => $template->client_id,
            'phone_id' => $template->phone_id,
            'location_id' => $template->location_id,
            'department_id' => $template->department_id,
            'created_by' => $dispatcher->id,
            'source' => 'manual',
            'status' => 'pending',
            'planned_date' => now()->addWeek()->toDateString(),
            'notes' => 'Reschedule me',
        ]);

        $board = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$template->department_id)
            ->assertOk()
            ->json();
        $this->assertTrue(collect($board['planned'])->pluck('id')->contains($order->id));

        $this->actingAs($dispatcher)
            ->putJson('/api/orders/'.$order->id, ['planned_date' => now()->toDateString()])
            ->assertOk();

        $todayBoard = $this->actingAs($dispatcher)
            ->getJson('/api/dispatch/board?department_id='.$template->department_id)
            ->assertOk()
            ->json();
        $this->assertTrue(collect($todayBoard['unassigned'])->pluck('id')->contains($order->id));
        $this->assertFalse(collect($todayBoard['planned'])->pluck('id')->contains($order->id));
    }

    public function test_client_can_be_created_with_only_a_name(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();

        $this->actingAs($user)
            ->postJson('/api/clients', ['name' => 'Name Only Co'])
            ->assertCreated()
            ->assertJsonPath('name', 'Name Only Co')
            ->assertJsonPath('phones', [])
            ->assertJsonPath('locations', []);

        $this->actingAs($user)
            ->postJson('/api/clients', [
                'name' => 'Empty Contact Co',
                'phones' => [],
                'locations' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('phones', [])
            ->assertJsonPath('locations', []);
    }

    public function test_created_by_is_the_acting_user_and_seeded_rows_use_admin(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $this->assertSame(1, (int) $admin->id);
        $this->assertSame(1, (int) $admin->created_by);
        $this->assertSame(1, (int) Role::query()->where('slug', 'admin')->value('created_by'));
        $this->assertSame(1, (int) Account::query()->where('code', '1000')->value('created_by'));
        $this->assertSame(1, (int) Item::query()->where('sku', 'FLT-20')->value('created_by'));

        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $this->actingAs($user)
            ->postJson('/api/clients', ['name' => 'Creator Client'])
            ->assertCreated()
            ->assertJsonPath('created_by', $user->id);

        $source = Contract::query()->where('type', 'warranty')->first();
        $this->actingAs($user)
            ->postJson('/api/contracts', [
                'client_id' => $source->client_id,
                'location_id' => $source->location_id,
                'department_id' => $source->department_id,
                'type' => 'warranty',
                'includes_spare_parts' => false,
                'includes_compressor_warranty' => true,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'planned_visits' => 0,
                'machine_ids' => $source->machines()->pluck('ac_machines.id')->all(),
            ])
            ->assertOk()
            ->assertJsonPath('created_by', $user->id)
            ->assertJsonPath('includes_spare_parts', true);
    }

    public function test_client_location_requires_country_area_block_and_street(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();

        $this->actingAs($user)
            ->postJson('/api/clients', [
                'name' => 'Incomplete Address Co',
                'locations' => [[
                    'label' => 'Home',
                    'country' => 'Kuwait',
                    'city' => 'Kuwait City',
                    'area' => 'Salmiya',
                    'block' => '4',
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_client_location_allows_empty_city(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();

        $this->actingAs($user)
            ->postJson('/api/clients', [
                'name' => 'No City Co',
                'locations' => [[
                    'label' => 'Home',
                    'country' => 'Kuwait',
                    'area' => 'Salmiya',
                    'block' => '4',
                    'street' => '12',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('locations.0.city', '');
    }

    public function test_dispatcher_can_list_invoices(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $this->actingAs($dispatcher)
            ->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_orders_index_supports_filters(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $today = now()->toDateString();

        $options = $this->actingAs($dispatcher)->getJson('/api/orders/filter-options')->assertOk()->json();
        $this->assertTrue(collect($options['creators'])->contains(fn ($row) => $row['id'] === $callCenter->id));
        $this->assertTrue(collect($options['technicians'])->contains(fn ($row) => $row['id'] === $tech->id));

        $pending = ServiceOrder::query()->where('status', 'pending')->first();
        $assigned = ServiceOrder::query()->where('status', 'assigned')->first();
        $this->assertNotNull($pending);
        $this->assertNotNull($assigned);

        $byNumber = $this->actingAs($dispatcher)->getJson('/api/orders?number='.$pending->id)->assertOk()->json();
        $this->assertCount(1, $byNumber);
        $this->assertSame($pending->id, $byNumber[0]['id']);

        $byStatus = $this->actingAs($dispatcher)->getJson('/api/orders?status[]=pending&status[]=assigned')->assertOk()->json();
        $this->assertNotEmpty($byStatus);
        $this->assertTrue(collect($byStatus)->every(fn ($row) => in_array($row['status'], ['pending', 'assigned'], true)));

        $byDept = $this->actingAs($dispatcher)->getJson('/api/orders?department_id[]='.$pending->department_id)->assertOk()->json();
        $this->assertNotEmpty($byDept);
        $this->assertTrue(collect($byDept)->every(fn ($row) => (int) $row['department_id'] === (int) $pending->department_id));

        $byClient = $this->actingAs($dispatcher)->getJson('/api/orders?client_name=Sara')->assertOk()->json();
        $this->assertNotEmpty($byClient);
        $this->assertTrue(collect($byClient)->every(fn ($row) => str_contains($row['client']['name'], 'Sara')));

        $byPhone = $this->actingAs($dispatcher)->getJson('/api/orders?client_phone=5550101')->assertOk()->json();
        $this->assertNotEmpty($byPhone);

        $byCreator = $this->actingAs($dispatcher)->getJson('/api/orders?created_by[]='.$callCenter->id)->assertOk()->json();
        $this->assertTrue(collect($byCreator)->every(fn ($row) => (int) $row['created_by'] === (int) $callCenter->id));

        $byTech = $this->actingAs($dispatcher)->getJson('/api/orders?technician_id[]='.$tech->id)->assertOk()->json();
        $this->assertNotEmpty($byTech);
        $this->assertTrue(collect($byTech)->every(fn ($row) => (int) $row['technician_id'] === (int) $tech->id));

        $pending->update(['planned_date' => '2026-09-15']);
        $byDue = $this->actingAs($dispatcher)->getJson('/api/orders?due_from=2026-09-15&due_to=2026-09-15')->assertOk()->json();
        $this->assertTrue(collect($byDue)->contains(fn ($row) => $row['id'] === $pending->id));

        $byCreated = $this->actingAs($dispatcher)->getJson('/api/orders?created_from='.$today.'&created_to='.$today)->assertOk()->json();
        $this->assertNotEmpty($byCreated);

        $held = ServiceOrder::query()->where('status', 'on_hold')->first();
        $this->assertNotNull($held);
        app(OrderService::class)->cancel($held, $dispatcher, 'Filter test', app(InvoiceService::class));
        $byCancel = $this->actingAs($dispatcher)->getJson('/api/orders?cancelled_from='.$today.'&cancelled_to='.$today)->assertOk()->json();
        $this->assertTrue(collect($byCancel)->contains(fn ($row) => $row['id'] === $held->id));

        $assigned->update(['completed_at' => now()]);
        $byDone = $this->actingAs($dispatcher)->getJson('/api/orders?completed_from='.$today.'&completed_to='.$today)->assertOk()->json();
        $this->assertTrue(collect($byDone)->contains(fn ($row) => $row['id'] === $assigned->id));

        $invoiced = ServiceOrder::query()->whereDoesntHave('invoice')->where('id', '!=', $held->id)->first();
        $this->assertNotNull($invoiced);
        $invoice = Invoice::query()->create([
            'order_id' => $invoiced->id,
            'created_by' => $admin->id,
            'status' => 'confirmed',
            'subtotal' => 100,
            'discount' => 0,
            'total' => 100,
            'contract_cost' => 0,
            'report' => 'Filter test',
        ]);

        $withInvoice = $this->actingAs($dispatcher)->getJson('/api/orders?has_invoice=1')->assertOk()->json();
        $this->assertTrue(collect($withInvoice)->contains(fn ($row) => $row['id'] === $invoiced->id));
        $withoutInvoice = $this->actingAs($dispatcher)->getJson('/api/orders?has_invoice=0')->assertOk()->json();
        $this->assertFalse(collect($withoutInvoice)->contains(fn ($row) => $row['id'] === $invoiced->id));

        $unpaid = $this->actingAs($dispatcher)->getJson('/api/orders?payment_status[]=unpaid')->assertOk()->json();
        $this->assertTrue(collect($unpaid)->contains(fn ($row) => $row['id'] === $invoiced->id));

        PaymentAllocation::query()->create([
            'client_id' => $invoiced->client_id,
            'invoice_id' => $invoice->id,
            'applied_by' => $admin->id,
            'created_by' => $admin->id,
            'amount' => 40,
        ]);
        $partial = $this->actingAs($dispatcher)->getJson('/api/orders?payment_status[]=partial')->assertOk()->json();
        $this->assertTrue(collect($partial)->contains(fn ($row) => $row['id'] === $invoiced->id));

        PaymentAllocation::query()->create([
            'client_id' => $invoiced->client_id,
            'invoice_id' => $invoice->id,
            'applied_by' => $admin->id,
            'created_by' => $admin->id,
            'amount' => 60,
        ]);
        $paid = $this->actingAs($dispatcher)->getJson('/api/orders?payment_status[]=paid')->assertOk()->json();
        $this->assertTrue(collect($paid)->contains(fn ($row) => $row['id'] === $invoiced->id));
        $stillUnpaid = $this->actingAs($dispatcher)->getJson('/api/orders?payment_status[]=unpaid')->assertOk()->json();
        $this->assertFalse(collect($stillUnpaid)->contains(fn ($row) => $row['id'] === $invoiced->id));
    }

    public function test_item_can_have_a_nullable_department(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $maintenance = Department::query()->where('name_en', 'AC Maintenance')->first();

        $created = $this->actingAs($admin)
            ->postJson('/api/items', [
                'sku' => 'TST-DEPT',
                'name' => 'Departmented part',
                'type' => 'tracked_part',
                'valuation_method' => 'fifo',
                'cost' => 1,
                'default_price' => 2,
                'department_id' => $maintenance->id,
            ])
            ->assertCreated()
            ->assertJsonPath('department_id', $maintenance->id)
            ->assertJsonPath('department.name_en', 'AC Maintenance')
            ->json();

        $this->actingAs($admin)
            ->putJson('/api/items/'.$created['id'], ['department_id' => null])
            ->assertOk()
            ->assertJsonPath('department_id', null);

        $this->actingAs($admin)
            ->postJson('/api/items', [
                'sku' => 'TST-NONE',
                'name' => 'Shared part',
                'type' => 'tracked_part',
                'valuation_method' => 'fifo',
                'cost' => 1,
                'default_price' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('department_id', null);
    }

    public function test_technician_invoice_items_are_limited_to_the_order_department(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $maintenance = Department::query()->where('name_en', 'AC Maintenance')->first();
        $shared = Item::query()->where('sku', 'CLN-COIL')->first();
        $other = Item::query()->where('sku', 'CMP-5T')->first();

        $catalog = $this->actingAs($tech)
            ->getJson('/api/items?department_id='.$order->department_id)
            ->assertOk()
            ->json();
        $skus = collect($catalog)->pluck('sku');
        $this->assertTrue($skus->contains('SVC-VISIT'));
        $this->assertTrue($skus->contains('CLN-COIL'));
        $this->assertFalse($skus->contains('CMP-5T'));

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['item_id' => $other->id, 'quantity' => 1]],
                'report' => 'Tried to bill a compressor on a maintenance visit.',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson('/api/items')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'CMP-5T']);

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['item_id' => $shared->id, 'quantity' => 1]],
                'report' => 'Cleaned the coil and checked airflow.',
            ])
            ->assertOk();
    }

    public function test_unsellable_items_are_hidden_from_invoice_catalog_and_cannot_be_invoiced(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $pump = Item::query()->where('sku', 'VAC-PUMP')->first();
        $this->assertNotNull($pump);
        $this->assertFalse($pump->is_sellable);

        $all = $this->actingAs($tech)->getJson('/api/items?department_id='.$order->department_id)->assertOk()->json();
        $this->assertTrue(collect($all)->contains(fn ($row) => $row['sku'] === 'VAC-PUMP'));

        $catalog = $this->actingAs($tech)
            ->getJson('/api/items?department_id='.$order->department_id.'&sellable=1')
            ->assertOk()
            ->json();
        $this->assertFalse(collect($catalog)->contains(fn ($row) => $row['sku'] === 'VAC-PUMP'));
        $this->assertTrue(collect($catalog)->contains(fn ($row) => $row['sku'] === 'SVC-VISIT'));

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['item_id' => $pump->id, 'quantity' => 1]],
                'report' => 'Tried to bill a vacuum pump.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This item cannot be added to an invoice.');
    }

    public function test_technician_can_add_a_plain_text_invoice_line(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['description' => '', 'quantity' => 1]],
                'report' => 'Missing description should fail.',
            ])
            ->assertStatus(422);

        $invoice = $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['description' => 'Emergency after-hours call-out', 'quantity' => 1]],
                'report' => 'Customer needed an extra visit after hours.',
            ])
            ->assertOk()
            ->assertJsonPath('items.0.item_id', null)
            ->assertJsonPath('items.0.description', 'Emergency after-hours call-out')
            ->json();

        $this->actingAs($dispatcher)
            ->putJson('/api/invoices/'.$invoice['id'], [
                'items' => [[
                    'description' => 'Emergency after-hours call-out',
                    'quantity' => 1,
                    'unit_amount' => 40,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('total', 40);
    }

    public function test_dispatcher_can_add_invoice_lines_before_confirm(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->with(['warehouse', 'departments'])->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);
        $service = Item::query()->where('sku', 'SVC-VISIT')->first();

        $invoice = app(InvoiceService::class)->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
        ], 'Initial visit complete.');

        $this->actingAs($dispatcher)
            ->putJson('/api/invoices/'.$invoice->id, [
                'discount' => 0,
                'items' => [
                    ['item_id' => $service->id, 'quantity' => 1, 'unit_amount' => 80],
                    ['description' => 'Parking fee', 'quantity' => 1, 'unit_amount' => 5],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.1.description', 'Parking fee')
            ->assertJsonPath('total', 85);
    }

    public function test_invoice_machine_coverage_follows_contract_and_toggle(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()
            ->whereNotNull('contract_id')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereDoesntHave('invoices')
            ->first();
        $this->assertNotNull($order);
        $order->load('contract.machines');
        $covered = $order->contract->machines->first();
        $this->assertNotNull($covered);
        $service = Item::query()->where('sku', 'SVC-VISIT')->first();
        $this->assertNotNull($service);

        $extra = AcMachine::query()->create([
            'location_id' => $order->location_id,
            'brand' => 'Gree',
            'model' => 'Cassette',
            'serial' => 'GR-HIST-1',
            'created_by' => $dispatcher->id,
        ]);
        $other = AcMachine::query()->where('location_id', '!=', $order->location_id)->first();
        $this->assertNotNull($other);

        $invoice = $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [[
                    'item_id' => $service->id,
                    'quantity' => 1,
                    'unit_amount' => 80,
                    'machine_id' => $extra->id,
                    'is_covered' => true,
                ]],
                'report' => 'Worked on a unit that is not on the contract.',
            ])
            ->assertOk()
            ->assertJsonPath('items.0.machine_id', $extra->id)
            ->assertJsonPath('items.0.is_covered', false)
            ->assertJsonPath('total', 80)
            ->json();

        $this->actingAs($dispatcher)
            ->putJson('/api/invoices/'.$invoice['id'], [
                'items' => [[
                    'item_id' => $service->id,
                    'quantity' => 1,
                    'unit_amount' => 80,
                    'machine_id' => $covered->id,
                    'is_covered' => false,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.machine_id', $covered->id)
            ->assertJsonPath('items.0.is_covered', false)
            ->assertJsonPath('total', 80);

        $this->actingAs($dispatcher)
            ->putJson('/api/invoices/'.$invoice['id'], [
                'items' => [[
                    'item_id' => $service->id,
                    'quantity' => 1,
                    'unit_amount' => 80,
                    'machine_id' => $covered->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.is_covered', true)
            ->assertJsonPath('total', 0);

        $this->actingAs($dispatcher)
            ->putJson('/api/invoices/'.$invoice['id'], [
                'items' => [[
                    'item_id' => $service->id,
                    'quantity' => 1,
                    'unit_amount' => 80,
                    'machine_id' => $covered->id,
                    'is_covered' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.is_covered', true)
            ->assertJsonPath('total', 0);

        $this->actingAs($dispatcher)
            ->putJson('/api/invoices/'.$invoice['id'], [
                'items' => [[
                    'item_id' => $service->id,
                    'quantity' => 1,
                    'unit_amount' => 80,
                    'machine_id' => $other->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The machine must belong to the order location.');
    }

    public function test_staff_with_invoice_create_need_not_be_the_technician(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->where('status', 'pending')->first();
        $service = Item::query()->where('sku', 'SVC-VISIT')->first();

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['item_id' => $service->id, 'quantity' => 1, 'unit_amount' => 80]],
                'report' => 'Billed from the office before the visit.',
            ])
            ->assertOk()
            ->assertJsonPath('created_by', $admin->id)
            ->assertJsonPath('items.0.unit_amount', 80);

        $other = ServiceOrder::query()
            ->where('id', '!=', $order->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereDoesntHave('invoice')
            ->first();
        $this->actingAs($dispatcher)
            ->postJson("/api/orders/{$other->id}/invoice", [
                'items' => [['description' => 'Call-out fee', 'quantity' => 1, 'unit_amount' => 15]],
                'report' => 'Created by dispatch.',
            ])
            ->assertOk()
            ->assertJsonPath('created_by', $dispatcher->id)
            ->assertJsonPath('items.0.description', 'Call-out fee');
    }

    public function test_technician_cannot_invoice_an_order_assigned_to_someone_else(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $other = User::query()->where('email', 'tech2@example.test')->with('departments')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($order);
        app(OrderService::class)->assign($order, $other, $dispatcher, 0);

        $this->actingAs($tech)
            ->postJson("/api/orders/{$order->id}/invoice", [
                'items' => [['description' => 'Should not bill this job', 'quantity' => 1]],
                'report' => 'Wrong technician.',
            ])
            ->assertForbidden();
    }

    public function test_stock_levels_are_per_warehouse_and_item(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $tech->load('warehouse');

        $this->actingAs($callCenter)->getJson('/api/stock-levels')->assertForbidden();

        $all = $this->actingAs($admin)->getJson('/api/stock-levels')->assertOk()->json();
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('warehouse', $all[0]);
        $this->assertArrayHasKey('qty', $all[0]);
        $this->assertArrayHasKey('item_id', $all[0]);

        $van = $this->actingAs($tech)->getJson('/api/stock-levels')->assertOk()->json();
        $this->assertNotEmpty($van);
        foreach ($van as $row) {
            $this->assertSame($tech->warehouse->id, $row['warehouse_id']);
        }

        $wid = $all[0]['warehouse_id'];
        $filtered = $this->actingAs($admin)
            ->getJson('/api/stock-levels?warehouse_id='.$wid)
            ->assertOk()
            ->json();
        foreach ($filtered as $row) {
            $this->assertSame($wid, $row['warehouse_id']);
        }
    }

    public function test_transfer_and_adjustment_can_include_multiple_items(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $central = Warehouse::query()->where('name', 'Central')->first();
        $van2 = Warehouse::query()->where('name', 'Tech van 2')->first();
        $filter = Item::query()->where('sku', 'FLT-20')->first();
        $gas = Item::query()->where('sku', 'GAS-R410')->first();

        $createdTransfer = $this->actingAs($admin)
            ->postJson('/api/transfers', [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $van2->id,
                'lines' => [
                    ['item_id' => $filter->id, 'qty' => 2],
                    ['item_id' => $gas->id, 'qty' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->json();

        $createdAdj = $this->actingAs($admin)
            ->postJson('/api/adjustments', [
                'warehouse_id' => $central->id,
                'reason' => 'Cycle count mix',
                'lines' => [
                    ['item_id' => $filter->id, 'qty_delta' => 3, 'unit_cost' => 8],
                    ['item_id' => $gas->id, 'qty_delta' => -1],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->json();

        $draftTransfer = $this->actingAs($admin)
            ->postJson('/api/transfers', [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $van2->id,
                'lines' => [['item_id' => $filter->id, 'qty' => 1]],
            ])
            ->assertOk()
            ->json();
        $this->actingAs($admin)->deleteJson('/api/transfers/'.$draftTransfer['id'])->assertNoContent();

        $draftAdj = $this->actingAs($admin)
            ->postJson('/api/adjustments', [
                'warehouse_id' => $central->id,
                'reason' => 'Throwaway',
                'lines' => [['item_id' => $filter->id, 'qty_delta' => 1, 'unit_cost' => 8]],
            ])
            ->assertOk()
            ->json();
        $this->actingAs($admin)->deleteJson('/api/adjustments/'.$draftAdj['id'])->assertNoContent();

        $updatedTransfer = $this->actingAs($admin)
            ->putJson('/api/transfers/'.$createdTransfer['id'], [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $van2->id,
                'lines' => [
                    ['item_id' => $filter->id, 'qty' => 4],
                    ['item_id' => $gas->id, 'qty' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->json();
        $this->assertEquals(4, (float) $updatedTransfer['lines'][0]['qty']);

        $this->actingAs($admin)
            ->putJson('/api/adjustments/'.$createdAdj['id'], [
                'warehouse_id' => $central->id,
                'reason' => 'Updated cycle count',
                'lines' => [
                    ['item_id' => $filter->id, 'qty_delta' => 1, 'unit_cost' => 8],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('reason', 'Updated cycle count')
            ->assertJsonCount(1, 'lines');

        $this->actingAs($admin)->postJson('/api/transfers/'.$createdTransfer['id'].'/send')->assertOk();
        $this->actingAs($admin)
            ->putJson('/api/transfers/'.$createdTransfer['id'], [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $van2->id,
                'lines' => [['item_id' => $filter->id, 'qty' => 1]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/adjustments/'.$createdAdj['id'].'/post')->assertOk();
        $this->actingAs($admin)
            ->putJson('/api/adjustments/'.$createdAdj['id'], [
                'warehouse_id' => $central->id,
                'reason' => 'Too late',
                'lines' => [['item_id' => $filter->id, 'qty_delta' => 1, 'unit_cost' => 8]],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)->deleteJson('/api/transfers/'.$createdTransfer['id'])->assertStatus(422);
        $this->actingAs($admin)->deleteJson('/api/adjustments/'.$createdAdj['id'])->assertStatus(422);
    }

    public function test_only_assigned_technician_can_receive_van_transfer(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $techOne = User::query()->where('email', 'tech@example.test')->first();
        $techTwo = User::query()->where('email', 'tech2@example.test')->first();
        $central = Warehouse::query()->where('name', 'Central')->first();
        $van2 = Warehouse::query()->where('name', 'Tech van 2')->first();
        $filter = Item::query()->where('sku', 'FLT-20')->first();

        $vanTransfer = $this->actingAs($dispatcher)
            ->postJson('/api/transfers', [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $van2->id,
                'lines' => [['item_id' => $filter->id, 'qty' => 1]],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($dispatcher)->postJson('/api/transfers/'.$vanTransfer['id'].'/send')->assertOk();

        $this->actingAs($dispatcher)
            ->postJson('/api/transfers/'.$vanTransfer['id'].'/receive')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only the assigned technician can receive this transfer.');

        $this->actingAs($admin)
            ->postJson('/api/transfers/'.$vanTransfer['id'].'/receive')
            ->assertStatus(422);

        $this->actingAs($techOne)
            ->postJson('/api/transfers/'.$vanTransfer['id'].'/receive')
            ->assertStatus(422);

        $listed = $this->actingAs($techTwo)->getJson('/api/transfers')->assertOk()->json();
        $this->assertTrue(collect($listed)->contains(fn ($row) => (int) $row['id'] === (int) $vanTransfer['id']));

        $otherListed = $this->actingAs($techOne)->getJson('/api/transfers')->assertOk()->json();
        $this->assertFalse(collect($otherListed)->contains(fn ($row) => (int) $row['id'] === (int) $vanTransfer['id']));

        $this->actingAs($techTwo)
            ->postJson('/api/transfers/'.$vanTransfer['id'].'/receive')
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('received_by', $techTwo->id);

        $south = $this->actingAs($admin)
            ->postJson('/api/warehouses', ['name' => 'South depot', 'type' => 'central'])
            ->assertSuccessful()
            ->json();

        $office = $this->actingAs($dispatcher)
            ->postJson('/api/transfers', [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $south['id'],
                'lines' => [['item_id' => $filter->id, 'qty' => 1]],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($dispatcher)->postJson('/api/transfers/'.$office['id'].'/send')->assertOk();

        $this->actingAs($techTwo)
            ->postJson('/api/transfers/'.$office['id'].'/receive')
            ->assertStatus(422);

        $this->actingAs($dispatcher)
            ->postJson('/api/transfers/'.$office['id'].'/receive')
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_adjustment_can_deduct_lost_stock(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $central = Warehouse::query()->where('name', 'Central')->first();
        $filter = Item::query()->where('sku', 'FLT-20')->first();
        $inventory = app(InventoryService::class);
        $before = $inventory->onHand($central->id, $filter->id);

        $adj = $this->actingAs($admin)
            ->postJson('/api/adjustments', [
                'warehouse_id' => $central->id,
                'reason' => 'Lost: van count',
                'lines' => [['item_id' => $filter->id, 'qty_delta' => -2]],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($admin)->postJson('/api/adjustments/'.$adj['id'].'/post')->assertOk();

        $this->assertSame(
            0,
            bccomp(bcsub($before, '2', 4), $inventory->onHand($central->id, $filter->id), 4)
        );
    }

    public function test_admin_can_manage_roles_and_departments(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $dispatchId = Permission::query()->where('slug', 'orders.dispatch')->value('id');

        $created = $this->actingAs($admin)
            ->postJson('/api/roles', [
                'name' => 'Supervisor',
                'permission_ids' => [$dispatchId],
            ])
            ->assertCreated()
            ->assertJsonPath('slug', 'supervisor')
            ->json();

        $this->actingAs($admin)
            ->putJson('/api/roles/'.$created['id'], [
                'name' => 'Lead supervisor',
                'permission_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Lead supervisor')
            ->assertJsonPath('permissions', []);

        $adminRole = Role::query()->where('slug', 'admin')->first();
        $this->actingAs($admin)
            ->putJson('/api/roles/'.$adminRole->id.'/permissions', ['permission_ids' => []])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->deleteJson('/api/roles/'.$adminRole->id)
            ->assertStatus(422);

        $techRole = Role::query()->where('slug', 'technician')->first();
        $this->actingAs($admin)
            ->deleteJson('/api/roles/'.$techRole->id)
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/departments', [
                'name_en' => 'Night shift',
                'name_ar' => 'الوردية الليلية',
            ])
            ->assertCreated()
            ->assertJsonPath('name_en', 'Night shift')
            ->assertJsonPath('name_ar', 'الوردية الليلية')
            ->assertJsonMissingPath('name');

        $night = Department::query()->where('name_en', 'Night shift')->first();
        $this->actingAs($admin)
            ->putJson('/api/departments/'.$night->id, ['name_en' => 'Nights', 'name_ar' => 'الليالي'])
            ->assertOk()
            ->assertJsonPath('name_en', 'Nights')
            ->assertJsonPath('name_ar', 'الليالي');
        $this->actingAs($admin)
            ->deleteJson('/api/departments/'.$night->id)
            ->assertNoContent();

        $used = Department::query()->has('orders')->first();
        $this->assertNotNull($used);
        $this->actingAs($admin)
            ->deleteJson('/api/departments/'.$used->id)
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson('/api/roles/'.$created['id'])
            ->assertNoContent();

        $dispatcher = Role::query()->where('slug', 'dispatcher')->first();
        $perm = $this->actingAs($admin)
            ->postJson('/api/permissions', [
                'name' => 'View reports',
                'slug' => 'reports.view',
                'group' => 'reports',
                'role_ids' => [$dispatcher->id],
            ])
            ->assertCreated()
            ->assertJsonPath('slug', 'reports.view')
            ->json();

        $this->assertTrue(
            collect($perm['roles'])->contains(fn ($role) => $role['slug'] === 'dispatcher')
        );

        $this->actingAs($admin)
            ->putJson('/api/permissions/'.$perm['id'], [
                'name' => 'View ops reports',
                'role_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('name', 'View ops reports');

        $this->actingAs($admin)
            ->deleteJson('/api/permissions/'.$perm['id'])
            ->assertNoContent();
    }

    public function test_dispatcher_cannot_manage_roles_or_departments(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();

        $this->actingAs($dispatcher)->postJson('/api/roles', ['name' => 'X'])->assertForbidden();
        $this->actingAs($dispatcher)->postJson('/api/permissions', ['name' => 'X'])->assertForbidden();
        $this->actingAs($dispatcher)->postJson('/api/departments', ['name' => 'X'])->assertForbidden();
    }

    public function test_order_status_colors_are_unique_and_editable(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();

        $list = $this->actingAs($dispatcher)->getJson('/api/order-statuses')->assertOk()->json();
        $this->assertCount(7, $list);
        $this->assertCount(7, collect($list)->pluck('color')->unique());
        $this->assertArrayHasKey('name_en', $list[0]);
        $this->assertArrayHasKey('name_ar', $list[0]);
        $this->assertArrayNotHasKey('name', $list[0]);

        $pending = collect($list)->firstWhere('slug', 'pending');
        $assigned = collect($list)->firstWhere('slug', 'assigned');

        $this->actingAs($dispatcher)
            ->putJson('/api/order-statuses/'.$pending['id'], [
                'color' => '#0EA5E9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_en', 'name_ar']);

        $this->actingAs($dispatcher)
            ->putJson('/api/order-statuses/'.$pending['id'], [
                'name_en' => 'New request',
                'color' => '#0EA5E9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name_ar']);

        $this->actingAs($dispatcher)
            ->putJson('/api/order-statuses/'.$pending['id'], [
                'name_en' => 'Waiting',
                'name_ar' => 'في الانتظار',
                'color' => $assigned['color'],
            ])
            ->assertStatus(422);

        $this->actingAs($dispatcher)
            ->putJson('/api/order-statuses/'.$pending['id'], [
                'name_en' => 'New request',
                'name_ar' => 'طلب جديد',
                'color' => '#0EA5E9',
            ])
            ->assertOk()
            ->assertJsonPath('name_en', 'New request')
            ->assertJsonPath('name_ar', 'طلب جديد')
            ->assertJsonMissingPath('name')
            ->assertJsonPath('color', '#0EA5E9');

        $this->actingAs($tech)
            ->putJson('/api/order-statuses/'.$pending['id'], [
                'name_en' => 'Hack',
                'name_ar' => 'اختراق',
                'color' => '#111111',
            ])
            ->assertForbidden();
    }

    public function test_global_search_returns_grouped_results_with_actions(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();

        $result = $this->actingAs($dispatcher)
            ->getJson('/api/search?q=Sara')
            ->assertOk()
            ->json();

        $types = collect($result['groups'])->pluck('type');
        $this->assertTrue($types->contains('client'));
        $clients = collect($result['groups'])->firstWhere('type', 'client')['items'];
        $this->assertTrue(collect($clients)->contains(fn ($row) => $row['title'] === 'Sara Ahmed'));
        $newOrder = collect($clients[0]['actions'])->firstWhere('label', 'New order');
        $this->assertNotNull($newOrder);
        $this->assertSame('create-order', $newOrder['modal'] ?? null);
        $this->assertSame($clients[0]['id'], $newOrder['client_id']);

        $order = ServiceOrder::query()->where('status', 'pending')->first();
        $this->assertNotNull($order);
        $byId = $this->actingAs($dispatcher)
            ->getJson('/api/search?q='.$order->id)
            ->assertOk()
            ->json();
        $this->assertTrue(collect($byId['groups'])->contains(fn ($group) => $group['type'] === 'order'));

        $techResult = $this->actingAs($tech)->getJson('/api/search?q=Sara')->assertOk()->json();
        $this->assertFalse(collect($techResult['groups'])->contains(fn ($group) => $group['type'] === 'client'));
    }

    public function test_dashboard_is_permission_aware(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();

        $this->getJson('/api/dashboard')->assertUnauthorized();

        $adminDash = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json();
        $this->assertNotNull($adminDash['orders']);
        $this->assertNotNull($adminDash['dispatch']);
        $this->assertNotNull($adminDash['clients']);
        $this->assertNotNull($adminDash['contracts']);
        $this->assertNotNull($adminDash['invoices']);
        $this->assertNotNull($adminDash['inventory']);
        $this->assertNotNull($adminDash['accounting']);
        $this->assertNotNull($adminDash['staff']);
        $this->assertCount(14, $adminDash['activity']);
        $this->assertArrayHasKey('orders', $adminDash['activity'][0]);
        $this->assertGreaterThan(0, $adminDash['clients']['total']);
        $this->assertGreaterThan(0, $adminDash['orders']['total']);

        $techDash = $this->actingAs($tech)->getJson('/api/dashboard')->assertOk()->json();
        $this->assertNotNull($techDash['tech']);
        $this->assertNull($techDash['orders']);
        $this->assertNull($techDash['invoices']);
        $this->assertNull($techDash['inventory']);
        $this->assertNull($techDash['activity']);
        $this->assertNull($techDash['clients']);
        $this->assertNull($techDash['dispatch']);
        $this->assertNull($techDash['accounting']);
        $this->assertNull($techDash['staff']);

        $ccDash = $this->actingAs($callCenter)->getJson('/api/dashboard')->assertOk()->json();
        $this->assertNotNull($ccDash['clients']);
        $this->assertNotNull($ccDash['orders']);
        $this->assertNotNull($ccDash['contracts']);
        $this->assertNull($ccDash['dispatch']);
        $this->assertNull($ccDash['inventory']);
        $this->assertNull($ccDash['accounting']);
    }

    public function test_dashboard_widget_can_be_hidden_without_losing_page_access(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $perm = Permission::query()->where('slug', 'dashboard.dispatch')->first();
        $this->assertNotNull($perm);

        $dispatcher->roles()->first()->permissions()->detach($perm->id);

        $dash = $this->actingAs($dispatcher)->getJson('/api/dashboard')->assertOk()->json();
        $this->assertNull($dash['dispatch']);
        $this->assertNotNull($dash['orders']);

        $this->actingAs($dispatcher)->getJson('/api/dispatch/board')->assertOk();
    }

    public function test_call_center_cannot_transfer_adjust_or_receive_stock(): void
    {
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $central = Warehouse::query()->where('name', 'Central')->first();
        $van = Warehouse::query()->where('name', 'Tech van 2')->first();
        $filter = Item::query()->where('sku', 'FLT-20')->first();

        $this->actingAs($callCenter)->getJson('/api/transfers')->assertForbidden();
        $this->actingAs($callCenter)->getJson('/api/adjustments')->assertForbidden();
        $this->actingAs($callCenter)
            ->postJson('/api/transfers', [
                'from_warehouse_id' => $central->id,
                'to_warehouse_id' => $van->id,
                'lines' => [['item_id' => $filter->id, 'qty' => 1]],
            ])
            ->assertForbidden();
        $this->actingAs($callCenter)
            ->postJson('/api/adjustments', [
                'warehouse_id' => $central->id,
                'reason' => 'Nope',
                'lines' => [['item_id' => $filter->id, 'qty_delta' => 1]],
            ])
            ->assertForbidden();
        $this->actingAs($callCenter)
            ->postJson('/api/warehouses/'.$central->id.'/receipts', [
                'item_id' => $filter->id,
                'qty' => 1,
                'unit_cost' => 1,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_create_a_central_warehouse(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();

        $this->actingAs($tech)
            ->postJson('/api/warehouses', ['name' => 'South depot', 'type' => 'central'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/api/warehouses', ['name' => 'South depot', 'type' => 'central'])
            ->assertSuccessful()
            ->assertJsonPath('name', 'South depot')
            ->assertJsonPath('type', 'central')
            ->assertJsonPath('technician_id', null);
    }

    public function test_admin_can_update_and_delete_an_unused_warehouse(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $central = Warehouse::query()->where('type', 'central')->first();

        $id = $this->actingAs($admin)
            ->postJson('/api/warehouses', ['name' => 'East depot', 'type' => 'central'])
            ->assertSuccessful()
            ->json('id');

        $this->actingAs($tech)
            ->putJson('/api/warehouses/'.$id, ['name' => 'East store', 'type' => 'central'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/warehouses/'.$id, ['name' => 'East store', 'type' => 'central'])
            ->assertSuccessful()
            ->assertJsonPath('name', 'East store');

        $this->actingAs($admin)
            ->deleteJson('/api/warehouses/'.$central->id)
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson('/api/warehouses/'.$id)
            ->assertSuccessful();
        $this->assertDatabaseMissing('warehouses', ['id' => $id]);
    }

    public function test_technician_can_accept_but_not_dispatch_or_delete_orders(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $assigned = ServiceOrder::query()->where('status', 'assigned')->where('technician_id', $tech->id)->first();
        $this->assertNotNull($assigned);

        $this->actingAs($tech)->postJson('/api/orders/'.$assigned->id.'/accept')->assertOk();

        $pending = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($pending);
        $this->actingAs($tech)
            ->postJson('/api/orders/'.$pending->id.'/assign', ['technician_id' => $tech->id])
            ->assertForbidden();
        $this->actingAs($tech)->deleteJson('/api/orders/'.$pending->id)->assertForbidden();
        $this->actingAs($tech)->getJson('/api/dispatch/board')->assertForbidden();
    }

    public function test_invoice_cannot_be_updated_without_invoices_update(): void
    {
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->with('warehouse')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereNull('contract_id')->where('status', 'pending')->first();
        $orders = app(OrderService::class);
        $orders->assign($order, $tech, $dispatcher, 0);
        $order = $orders->currentFor($tech);
        $orders->accept($order, $tech);
        $orders->reached($order->fresh(), $tech);
        $service = Item::query()->where('sku', 'SVC-VISIT')->first();
        $invoice = app(InvoiceService::class)->createDraft($order->fresh(), $tech, [
            ['item_id' => $service->id, 'quantity' => 1],
        ]);

        $this->actingAs($callCenter)
            ->putJson('/api/invoices/'.$invoice->id, ['discount' => 0])
            ->assertForbidden();
    }

    public function test_contracts_index_supports_filters(): void
    {
        $user = User::query()->where('email', 'callcenter@example.test')->first();
        $warranty = Contract::query()->where('type', 'warranty')->first();
        $annual = Contract::query()->where('type', 'annual')->first();
        $this->assertNotNull($warranty);
        $this->assertNotNull($annual);

        $byNumber = $this->actingAs($user)->getJson('/api/contracts?number='.$warranty->id)->assertOk()->json();
        $this->assertCount(1, $byNumber);
        $this->assertSame($warranty->id, $byNumber[0]['id']);

        $byType = $this->actingAs($user)->getJson('/api/contracts?type[]=warranty')->assertOk()->json();
        $this->assertNotEmpty($byType);
        $this->assertTrue(collect($byType)->every(fn ($row) => $row['type'] === 'warranty'));

        $byStatus = $this->actingAs($user)->getJson('/api/contracts?status[]=active')->assertOk()->json();
        $this->assertNotEmpty($byStatus);
        $this->assertTrue(collect($byStatus)->every(fn ($row) => $row['status'] === 'active'));

        $byDept = $this->actingAs($user)->getJson('/api/contracts?department_id[]='.$annual->department_id)->assertOk()->json();
        $this->assertNotEmpty($byDept);
        $this->assertTrue(collect($byDept)->every(fn ($row) => (int) $row['department_id'] === (int) $annual->department_id));

        $byClient = $this->actingAs($user)->getJson('/api/contracts?client_name=Sara')->assertOk()->json();
        $this->assertNotEmpty($byClient);
        $this->assertTrue(collect($byClient)->every(fn ($row) => str_contains($row['client']['name'], 'Sara')));

        $byLocation = $this->actingAs($user)->getJson('/api/contracts?location=Home')->assertOk()->json();
        $this->assertNotEmpty($byLocation);
        $this->assertTrue(collect($byLocation)->every(fn ($row) => str_contains($row['location']['label'] ?? '', 'Home')));

        $byLocationId = $this->actingAs($user)->getJson('/api/contracts?location_id='.$annual->location_id)->assertOk()->json();
        $this->assertNotEmpty($byLocationId);
        $this->assertTrue(collect($byLocationId)->every(fn ($row) => (int) $row['location_id'] === (int) $annual->location_id));

        $today = now()->toDateString();
        $byStart = $this->actingAs($user)->getJson('/api/contracts?start_from='.$today.'&start_to='.$today)->assertOk()->json();
        $this->assertTrue(collect($byStart)->contains(fn ($row) => $row['id'] === $warranty->id));

        $withParts = $this->actingAs($user)->getJson('/api/contracts?includes_spare_parts=1')->assertOk()->json();
        $this->assertNotEmpty($withParts);
        $this->assertTrue(collect($withParts)->every(fn ($row) => $row['includes_spare_parts'] === true));
        $this->assertTrue(collect($withParts)->contains(fn ($row) => $row['id'] === $annual->id));

        $withCompressor = $this->actingAs($user)->getJson('/api/contracts?includes_compressor_warranty=1')->assertOk()->json();
        $this->assertNotEmpty($withCompressor);
        $this->assertTrue(collect($withCompressor)->every(fn ($row) => $row['includes_compressor_warranty'] === true));
        $this->assertTrue(collect($withCompressor)->contains(fn ($row) => $row['id'] === $warranty->id));
    }

    public function test_contract_can_be_updated(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $warranty = Contract::query()->where('type', 'warranty')->first();
        $this->assertNotNull($warranty);

        $this->actingAs($tech)
            ->putJson('/api/contracts/'.$warranty->id, $this->contractPayload($warranty, ['planned_visits' => 2]))
            ->assertForbidden();

        $updated = $this->actingAs($callCenter)
            ->putJson('/api/contracts/'.$warranty->id, $this->contractPayload($warranty, [
                'includes_spare_parts' => true,
                'planned_visits' => 2,
                'end_date' => $warranty->end_date->copy()->addMonth()->toDateString(),
            ]))
            ->assertOk()
            ->json();

        $this->assertTrue($updated['includes_spare_parts']);
        $this->assertSame(2, $updated['planned_visits']);
        $this->assertCount(2, collect($updated['orders'])->where('source', 'contract_schedule')->all());

        $annual = Contract::query()->where('type', 'annual')->first();
        $this->assertNotNull($annual);
        $this->assertTrue($annual->installments()->where('status', 'billed')->exists());

        $this->actingAs($admin)
            ->putJson('/api/contracts/'.$annual->id, $this->contractPayload($annual, ['includes_spare_parts' => false]))
            ->assertOk()
            ->assertJsonPath('includes_spare_parts', false);

        $this->actingAs($admin)
            ->putJson('/api/contracts/'.$annual->id, $this->contractPayload($annual->fresh(), ['total_amount' => 9999]))
            ->assertStatus(422);

        $fresh = app(ContractService::class)->create([
            'client_id' => $warranty->client_id,
            'location_id' => $warranty->location_id,
            'department_id' => $warranty->department_id,
            'type' => 'annual',
            'includes_spare_parts' => false,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'total_amount' => 1200,
            'payment_count' => 4,
            'planned_visits' => 1,
            'machine_ids' => $warranty->machines()->pluck('ac_machines.id')->all(),
        ], $admin);

        $this->assertSame(4, $fresh->installments()->count());

        $this->actingAs($admin)
            ->putJson('/api/contracts/'.$fresh->id, $this->contractPayload($fresh, [
                'total_amount' => 800,
                'payment_count' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('payment_count', 2);

        $this->assertSame(2, $fresh->installments()->count());
    }

    public function test_contract_keeps_custom_installment_and_visit_dates(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $source = Contract::query()->where('type', 'warranty')->first();
        $this->assertNotNull($source);

        $created = $this->actingAs($admin)
            ->postJson('/api/contracts', [
                'client_id' => $source->client_id,
                'location_id' => $source->location_id,
                'department_id' => $source->department_id,
                'type' => 'annual',
                'includes_spare_parts' => false,
                'includes_compressor_warranty' => false,
                'start_date' => '2026-08-29',
                'end_date' => '2027-08-29',
                'total_amount' => 900,
                'machine_ids' => $source->machines()->pluck('ac_machines.id')->all(),
                'installments' => [
                    ['due_date' => '2026-09-15', 'amount' => 400, 'description' => 'Down payment'],
                    ['due_date' => '2026-12-01', 'amount' => 500, 'description' => 'Final payment'],
                ],
                'visits' => [
                    ['planned_date' => '2026-10-10'],
                    ['planned_date' => '2027-02-20'],
                ],
            ])
            ->assertOk()
            ->json();

        $this->assertSame(
            ['2026-09-15', '2026-12-01'],
            collect($created['installments'])->pluck('due_date')->map(fn ($d) => substr((string) $d, 0, 10))->all()
        );
        $this->assertSame(
            ['Down payment', 'Final payment'],
            collect($created['installments'])->pluck('description')->all()
        );
        $this->assertSame(
            ['2026-10-10', '2027-02-20'],
            collect($created['orders'])->pluck('planned_date')->map(fn ($d) => substr((string) $d, 0, 10))->all()
        );

        $this->actingAs($admin)
            ->postJson('/api/contracts', [
                'client_id' => $source->client_id,
                'location_id' => $source->location_id,
                'department_id' => $source->department_id,
                'type' => 'annual',
                'start_date' => '2026-08-29',
                'end_date' => '2027-08-29',
                'total_amount' => 900,
                'installments' => [
                    ['due_date' => '2026-09-15', 'amount' => 400],
                    ['due_date' => '2026-12-01', 'amount' => 400],
                ],
                'visits' => [],
            ])
            ->assertStatus(422);

        $firstVisit = $created['orders'][0];
        $updated = $this->actingAs($admin)
            ->putJson('/api/contracts/'.$created['id'], [
                'client_id' => $source->client_id,
                'location_id' => $source->location_id,
                'department_id' => $source->department_id,
                'type' => 'annual',
                'includes_spare_parts' => false,
                'includes_compressor_warranty' => false,
                'start_date' => '2026-08-29',
                'end_date' => '2027-08-29',
                'total_amount' => 900,
                'machine_ids' => $source->machines()->pluck('ac_machines.id')->all(),
                'installments' => collect($created['installments'])->map(fn ($row, $i) => [
                    'id' => $row['id'],
                    'due_date' => $i === 1 ? '2027-01-10' : substr((string) $row['due_date'], 0, 10),
                    'amount' => $row['amount'],
                    'description' => $i === 0 ? 'Updated down payment' : $row['description'],
                ])->all(),
                'visits' => [
                    ['id' => $firstVisit['id'], 'planned_date' => '2026-11-01'],
                    ['planned_date' => '2027-03-15'],
                ],
            ])
            ->assertOk()
            ->json();

        $this->assertSame(
            ['2026-09-15', '2027-01-10'],
            collect($updated['installments'])->pluck('due_date')->map(fn ($d) => substr((string) $d, 0, 10))->all()
        );
        $this->assertSame(
            ['Updated down payment', 'Final payment'],
            collect($updated['installments'])->pluck('description')->all()
        );
        $this->assertSame(
            ['2026-11-01', '2027-03-15'],
            collect($updated['orders'])->pluck('planned_date')->map(fn ($d) => substr((string) $d, 0, 10))->sort()->values()->all()
        );
    }

    public function test_office_can_collect_into_wallet_and_apply_to_dues(): void
    {
        $accountant = User::query()->where('email', 'accountant@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $installment = ContractInstallment::query()
            ->where('status', 'billed')
            ->first()
            ?? ContractInstallment::query()->where('status', 'pending')->first();
        $this->assertNotNull($installment);
        $clientId = $installment->contract()->value('client_id');
        $payments = app(PaymentService::class);

        $this->actingAs($callCenter)
            ->postJson('/api/payments', [
                'client_id' => $clientId,
                'amount' => 25,
                'method' => 'cash',
                'apply_to' => 'credit',
            ])
            ->assertForbidden();

        $credit = $this->actingAs($accountant)
            ->postJson('/api/payments', [
                'client_id' => $clientId,
                'amount' => 40,
                'method' => 'card',
                'apply_to' => 'credit',
            ])
            ->assertOk()
            ->json();

        $this->assertSame(40, $payments->walletBalance($clientId));
        $this->assertSame(0, count($credit['allocations'] ?? []));
        $collectJournal = JournalEntry::query()
            ->where('source_type', 'payment')
            ->where('source_id', $credit['id'])
            ->where('event_key', 'collect')
            ->first();
        $this->assertNotNull($collectJournal);
        $this->assertTrue($collectJournal->lines()->where('credit', 40)->whereHas('account', fn ($q) => $q->where('code', '2110'))->exists());
        $this->assertFalse($collectJournal->lines()->whereHas('account', fn ($q) => $q->where('code', '1100'))->exists());

        $this->actingAs($accountant)
            ->postJson('/api/payments/apply', [
                'client_id' => $clientId,
                'installment_id' => $installment->id,
                'amount' => 41,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Amount exceeds the client credit balance.');

        $this->actingAs($accountant)
            ->postJson('/api/payments', [
                'client_id' => $clientId,
                'installment_id' => $installment->id,
                'amount' => 25,
                'method' => 'wallet',
            ])
            ->assertOk();
        $this->assertSame(15, $payments->walletBalance($clientId));
        $this->assertSame(25, $payments->allocatedToInstallment($installment->id));

        $remaining = (int) $installment->amount - 25;
        $overpay = $this->actingAs($accountant)
            ->postJson('/api/payments', [
                'client_id' => $clientId,
                'installment_id' => $installment->id,
                'amount' => $remaining - 15 + 8,
                'method' => 'bank',
                'use_credit' => 15,
            ])
            ->assertOk()
            ->json();

        $installment->refresh();
        $this->assertSame('paid', $installment->status);
        $this->assertSame(8, $payments->walletBalance($clientId));
        $this->assertSame((int) $installment->amount, $payments->allocatedToInstallment($installment->id));
        $this->assertTrue(
            JournalEntry::query()
                ->where('source_type', 'contract_installment')
                ->where('source_id', $installment->id)
                ->where('event_key', 'due')
                ->exists()
        );
        $this->assertNotEmpty($overpay['allocations']);

        $this->actingAs($dispatcher)
            ->postJson('/api/payments/apply', [
                'client_id' => $clientId,
                'installment_id' => $installment->id,
                'amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This installment is already paid.');

        $pending = ContractInstallment::query()->where('status', 'pending')->first();
        $this->assertNotNull($pending);
        $this->actingAs($dispatcher)
            ->postJson('/api/payments', [
                'client_id' => $pending->contract()->value('client_id'),
                'installment_id' => $pending->id,
                'amount' => $pending->amount,
                'method' => 'cash',
            ])
            ->assertOk();
        $this->assertSame('paid', $pending->fresh()->status);
        $this->assertSame(0, $payments->installmentRemaining($pending->fresh()));

        $this->actingAs($accountant)
            ->postJson('/api/payments/apply', [
                'client_id' => $clientId,
                'amount' => 1,
            ])
            ->assertUnprocessable();
    }

    public function test_staff_can_comment_and_attach_on_an_order(): void
    {
        Storage::fake('local');
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();

        $this->actingAs($dispatcher)
            ->post("/api/order/{$order->id}/comments", [
                'body' => 'Bring extra filters.',
                'files' => [UploadedFile::fake()->create('note.pdf', 12, 'application/pdf')],
            ])
            ->assertOk()
            ->assertJsonPath('body', 'Bring extra filters.')
            ->assertJsonPath('kind', 'file')
            ->assertJsonPath('attachments.0.original_name', 'note.pdf');

        $this->assertDatabaseHas('attachments', [
            'original_name' => 'note.pdf',
            'disk' => 'local',
            'kind' => 'file',
        ]);
        $path = Attachment::query()->where('original_name', 'note.pdf')->value('path');
        $this->assertNotEmpty($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_voice_note_accepts_browser_recorder_mimes(): void
    {
        Storage::fake('local');
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();

        $this->actingAs($dispatcher)
            ->post("/api/order/{$order->id}/comments", [
                'kind' => 'voice',
                'files' => [UploadedFile::fake()->create('voice.webm', 8, 'video/webm')],
            ])
            ->assertOk()
            ->assertJsonPath('kind', 'voice')
            ->assertJsonPath('attachments.0.kind', 'audio')
            ->assertJsonPath('attachments.0.mime', 'audio/webm');

        $this->actingAs($dispatcher)
            ->post("/api/order/{$order->id}/comments", [
                'kind' => 'voice',
                'files' => [UploadedFile::fake()->create('voice.m4a', 8, 'audio/x-m4a')],
            ])
            ->assertOk()
            ->assertJsonPath('attachments.0.kind', 'audio');
    }

    public function test_technician_cannot_comment_on_another_tech_order(): void
    {
        $techTwo = User::query()->where('email', 'tech2@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();

        $this->actingAs($techTwo)
            ->postJson("/api/order/{$order->id}/comments", ['body' => 'Not my job'])
            ->assertForbidden();
    }

    public function test_technician_comment_is_unread_for_dispatcher(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $order = app(OrderService::class)->currentFor($tech);
        $this->assertNotNull($order);

        $this->actingAs($tech)->postJson('/api/orders/'.$order->id.'/accept')->assertOk();
        $this->actingAs($tech)
            ->postJson("/api/order/{$order->id}/comments", ['body' => 'On site, extra filters needed.'])
            ->assertOk();

        $this->assertDatabaseHas('comment_participants', [
            'commentable_type' => 'order',
            'commentable_id' => $order->id,
            'user_id' => $dispatcher->id,
        ]);

        $inbox = $this->actingAs($dispatcher)->getJson('/api/inbox')->assertOk();
        $this->assertGreaterThan(0, $inbox->json('unread_total'));
        $thread = collect($inbox->json('threads'))->first(
            fn ($row) => ($row['type'] ?? null) === 'order' && (int) $row['id'] === (int) $order->id
        );
        $this->assertNotNull($thread);
        $this->assertGreaterThan(0, $thread['unread']);
        $this->assertSame($order->department_id, $thread['department_id'] ?? null);
    }

    public function test_marking_comments_read_clears_inbox(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();

        $this->actingAs($tech)->postJson('/api/orders/'.$order->id.'/accept')->assertOk();

        $inbox = $this->actingAs($tech)->getJson('/api/inbox')->assertOk();
        $this->assertGreaterThan(0, $inbox->json('unread_total'));
        $thread = collect($inbox->json('threads'))->first(
            fn ($row) => ($row['type'] ?? null) === 'order' && (int) $row['id'] === (int) $order->id
        );
        $this->assertSame($order->department_id, $thread['department_id'] ?? null);

        $this->actingAs($tech)->postJson("/api/order/{$order->id}/comments/read")->assertOk();

        $this->actingAs($tech)
            ->getJson('/api/inbox')
            ->assertOk()
            ->assertJsonPath('unread_total', 0);
    }

    public function test_attachment_download_is_forbidden_without_view(): void
    {
        Storage::fake('local');
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $techTwo = User::query()->where('email', 'tech2@example.test')->first();
        $order = ServiceOrder::query()->where('notes', 'Quarterly checkup')->first();

        $comment = $this->actingAs($dispatcher)
            ->post("/api/order/{$order->id}/comments", [
                'files' => [UploadedFile::fake()->create('secret.pdf', 8, 'application/pdf')],
            ])
            ->assertOk();

        $id = $comment->json('attachments.0.id');
        $this->actingAs($techTwo)->get("/api/attachments/{$id}")->assertForbidden();
        $this->actingAs($dispatcher)->get("/api/attachments/{$id}")->assertOk();
    }

    public function test_comments_list_returns_latest_page_and_older(): void
    {
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $order = ServiceOrder::query()->whereDoesntHave('comments')->first();
        $this->assertNotNull($order);

        foreach (range(1, 12) as $n) {
            $this->actingAs($dispatcher)
                ->postJson("/api/order/{$order->id}/comments", ['body' => "Note {$n}"])
                ->assertOk();
        }

        $first = $this->actingAs($dispatcher)
            ->getJson("/api/order/{$order->id}/comments")
            ->assertOk();
        $this->assertCount(10, $first->json('comments'));
        $this->assertTrue($first->json('has_more'));
        $this->assertSame('Note 3', $first->json('comments.0.body'));
        $this->assertSame('Note 12', $first->json('comments.9.body'));

        $oldest = $first->json('comments.0.id');
        $older = $this->actingAs($dispatcher)
            ->getJson("/api/order/{$order->id}/comments?before={$oldest}")
            ->assertOk();
        $this->assertCount(2, $older->json('comments'));
        $this->assertFalse($older->json('has_more'));
        $this->assertSame('Note 1', $older->json('comments.0.body'));
        $this->assertSame('Note 2', $older->json('comments.1.body'));
    }

    public function test_assigning_an_order_pushes_only_that_technician(): void
    {
        PushService::fake();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $techTwo = User::query()->where('email', 'tech2@example.test')->first();
        $pending = ServiceOrder::query()->where('status', 'pending')->whereNull('technician_id')->first();
        $this->assertNotNull($pending);

        $this->actingAs($dispatcher)
            ->postJson('/api/orders/'.$pending->id.'/assign', ['technician_id' => $tech->id])
            ->assertOk();

        $this->assertTrue(collect(PushService::$sent)->contains(
            fn ($row) => (int) $row['user_id'] === (int) $tech->id && str_contains($row['title'], '#'.$pending->id)
        ));
        $this->assertFalse(collect(PushService::$sent)->contains(
            fn ($row) => (int) $row['user_id'] === (int) $techTwo->id
        ));
    }

    public function test_comment_push_skips_author_and_notifies_technician(): void
    {
        PushService::fake();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $tech = User::query()->where('email', 'tech@example.test')->first();
        $order = app(OrderService::class)->currentFor($tech);
        $this->assertNotNull($order);
        $this->actingAs($tech)->postJson('/api/orders/'.$order->id.'/accept')->assertOk();

        PushService::fake();
        $this->actingAs($tech)
            ->postJson('/api/order/'.$order->id.'/comments', ['body' => 'Need more gas'])
            ->assertOk();
        $this->assertFalse(collect(PushService::$sent)->contains(
            fn ($row) => (int) $row['user_id'] === (int) $tech->id
        ));
        $this->assertTrue(collect(PushService::$sent)->contains(
            fn ($row) => (int) $row['user_id'] === (int) $dispatcher->id
        ));

        PushService::fake();
        $this->actingAs($dispatcher)
            ->postJson('/api/order/'.$order->id.'/comments', ['body' => 'On the way'])
            ->assertOk();
        $this->assertTrue(collect(PushService::$sent)->contains(
            fn ($row) => (int) $row['user_id'] === (int) $tech->id && str_contains($row['url'], '/tech')
        ));
        $this->assertFalse(collect(PushService::$sent)->contains(
            fn ($row) => (int) $row['user_id'] === (int) $dispatcher->id
        ));
    }

    public function test_technician_can_subscribe_for_web_push(): void
    {
        $tech = User::query()->where('email', 'tech@example.test')->first();

        $this->actingAs($tech)->getJson('/api/push/vapid')->assertOk()->assertJsonStructure(['public_key']);
        $this->actingAs($tech)
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://push.example.test/tech-one',
                'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
            ])
            ->assertOk();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $tech->id,
            'endpoint' => 'https://push.example.test/tech-one',
        ]);
        $this->actingAs($tech)
            ->deleteJson('/api/push/subscribe', ['endpoint' => 'https://push.example.test/tech-one'])
            ->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://push.example.test/tech-one',
        ]);
    }

    private function contractPayload(Contract $contract, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $contract->client_id,
            'location_id' => $contract->location_id,
            'department_id' => $contract->department_id,
            'type' => $contract->type,
            'status' => $contract->status,
            'includes_spare_parts' => $contract->includes_spare_parts,
            'includes_compressor_warranty' => $contract->includes_compressor_warranty,
            'compressor_warranty_start' => $contract->compressor_warranty_start?->toDateString(),
            'compressor_warranty_end' => $contract->compressor_warranty_end?->toDateString(),
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date->toDateString(),
            'total_amount' => $contract->total_amount,
            'payment_count' => $contract->payment_count,
            'planned_visits' => $contract->planned_visits,
            'machine_ids' => $contract->machines()->pluck('ac_machines.id')->all(),
        ], $overrides);
    }
}
