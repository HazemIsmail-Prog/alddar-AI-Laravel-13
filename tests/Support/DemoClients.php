<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Department;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Comments\CommentService;
use App\Services\Contracts\ContractService;
use App\Services\Invoices\InvoiceService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Auth;

class DemoClients
{
    public static function seed(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $callCenter = User::query()->where('email', 'callcenter@example.test')->first();
        $dispatcher = User::query()->where('email', 'dispatcher@example.test')->first();
        $technician = User::query()->where('email', 'tech@example.test')->first();
        $technician2 = User::query()->where('email', 'tech2@example.test')->first();
        $maintenance = Department::query()->where('name_en', 'AC Maintenance')->first();
        $install = Department::query()->where('name_en', 'Installation')->first();

        Auth::login($admin);

        $homeowner = Client::query()->updateOrCreate(['name' => 'Sara Ahmed'], ['notes' => 'Prefers morning visits']);
        if ($homeowner->phones()->count() === 0) {
            $homeowner->phones()->create(['country_code' => '+965', 'phone' => '555-0101', 'is_primary' => true]);
            $home = $homeowner->locations()->create([
                'label' => 'Home',
                'country' => 'Kuwait',
                'city' => 'Kuwait City',
                'area' => 'Mishref',
                'block' => '4',
                'street' => '12',
                'avenue' => '3',
                'building' => '8',
                'floor' => '2',
                'flat' => '5',
                'extras' => null,
                'paci_number' => 28450123,
                'google_maps_link' => 'https://maps.google.com/?q=29.2776,48.0698',
            ]);
            $office = $homeowner->locations()->create([
                'label' => 'Office',
                'country' => 'Kuwait',
                'city' => 'Kuwait City',
                'area' => 'Sharq',
                'block' => '1',
                'street' => '88',
                'avenue' => null,
                'building' => 'Nile Tower',
                'floor' => '6',
                'flat' => null,
                'extras' => 'Ring reception',
                'paci_number' => null,
                'google_maps_link' => null,
            ]);
            $home->machines()->create(['brand' => 'Carrier', 'model' => 'X18', 'serial' => 'CR-1001']);
            $office->machines()->create(['brand' => 'Daikin', 'model' => 'FTX', 'serial' => 'DK-2002']);
        }
        $officeClient = Client::query()->updateOrCreate(['name' => 'Nile Offices LLC'], ['notes' => 'Building A']);
        if ($officeClient->phones()->count() === 0) {
            $officeClient->phones()->create(['country_code' => '+965', 'phone' => '555-0202', 'is_primary' => true]);
            $loc = $officeClient->locations()->create([
                'label' => 'HQ',
                'country' => 'Kuwait',
                'city' => 'Kuwait City',
                'area' => 'Sharq',
                'block' => '7',
                'street' => 'Corniche',
                'avenue' => null,
                'building' => '1',
                'floor' => '3',
                'flat' => '12',
                'extras' => null,
                'google_maps_link' => 'https://maps.google.com/?q=29.3759,47.9774',
            ]);
            $loc->machines()->create(['brand' => 'LG', 'model' => 'Artcool', 'serial' => 'LG-3300']);
            $loc->machines()->create(['brand' => 'LG', 'model' => 'Artcool', 'serial' => 'LG-3301']);
        }

        $homeowner->load('locations.machines');
        $officeClient->load('locations.machines');
        $homeLoc = $homeowner->locations->firstWhere('label', 'Home') ?? $homeowner->locations->first();
        $hq = $officeClient->locations->first();

        $contracts = app(ContractService::class);
        if (Contract::query()->count() === 0) {
            $contracts->create([
                'client_id' => $officeClient->id,
                'location_id' => $hq->id,
                'department_id' => $maintenance->id,
                'type' => 'annual',
                'includes_spare_parts' => true,
                'includes_compressor_warranty' => false,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'total_amount' => 2400,
                'payment_count' => 4,
                'planned_visits' => 2,
                'machine_ids' => $hq->machines->pluck('id')->all(),
            ], $admin);

            $contracts->create([
                'client_id' => $homeowner->id,
                'location_id' => $homeLoc->id,
                'department_id' => $maintenance->id,
                'type' => 'warranty',
                'includes_spare_parts' => true,
                'includes_compressor_warranty' => true,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'planned_visits' => 1,
                'machine_ids' => $homeLoc->machines->pluck('id')->all(),
            ], $admin);

            $contracts->billDueInstallments($admin);
        }

        $orderService = app(OrderService::class);
        if (ServiceOrder::query()->where('source', 'manual')->count() === 0) {
            $today = now()->toDateString();
            $later = now()->addDays(5)->toDateString();

            $pending = ServiceOrder::query()->create([
                'client_id' => $homeowner->id,
                'phone_id' => $homeowner->defaultPhone()->id,
                'location_id' => $homeLoc->id,
                'department_id' => $maintenance->id,
                'created_by' => $callCenter->id,
                'source' => 'manual',
                'status' => 'pending',
                'planned_date' => $today,
                'notes' => 'No cooling in bedroom',
            ]);
            $pending->statusHistory()->create([
                'user_id' => $callCenter->id,
                'created_by' => $callCenter->id,
                'from_status' => null,
                'to_status' => 'pending',
                'reason' => 'Order created',
            ]);

            $assigned = ServiceOrder::query()->create([
                'client_id' => $officeClient->id,
                'phone_id' => $officeClient->defaultPhone()->id,
                'location_id' => $hq->id,
                'department_id' => $maintenance->id,
                'contract_id' => Contract::query()->where('type', 'annual')->value('id'),
                'created_by' => $callCenter->id,
                'source' => 'manual',
                'status' => 'pending',
                'planned_date' => $today,
                'notes' => 'Quarterly checkup',
            ]);
            $assigned->statusHistory()->create([
                'user_id' => $callCenter->id,
                'created_by' => $callCenter->id,
                'from_status' => null,
                'to_status' => 'pending',
            ]);
            $orderService->assign($assigned, $technician, $dispatcher, 1);

            $hold = ServiceOrder::query()->create([
                'client_id' => $homeowner->id,
                'phone_id' => $homeowner->defaultPhone()->id,
                'location_id' => $homeLoc->id,
                'department_id' => $maintenance->id,
                'created_by' => $callCenter->id,
                'source' => 'manual',
                'status' => 'pending',
                'planned_date' => $today,
                'notes' => 'Customer asked to pause',
            ]);
            $hold->statusHistory()->create([
                'user_id' => $callCenter->id,
                'created_by' => $callCenter->id,
                'from_status' => null,
                'to_status' => 'pending',
            ]);
            $orderService->assign($hold, $technician2, $dispatcher, 1);
            $orderService->hold($hold, $dispatcher, 'Client postponed the visit', app(InvoiceService::class));

            $installJob = ServiceOrder::query()->create([
                'client_id' => $homeowner->id,
                'phone_id' => $homeowner->defaultPhone()->id,
                'location_id' => $homeLoc->id,
                'department_id' => $install->id,
                'created_by' => $callCenter->id,
                'source' => 'manual',
                'status' => 'pending',
                'planned_date' => $today,
                'notes' => 'New split unit install',
            ]);
            $installJob->statusHistory()->create([
                'user_id' => $callCenter->id,
                'created_by' => $callCenter->id,
                'from_status' => null,
                'to_status' => 'pending',
            ]);
            $orderService->assign($installJob, $technician, $dispatcher, 1);

            $planned = ServiceOrder::query()->create([
                'client_id' => $homeowner->id,
                'phone_id' => $homeowner->defaultPhone()->id,
                'location_id' => $homeLoc->id,
                'department_id' => $maintenance->id,
                'created_by' => $callCenter->id,
                'source' => 'manual',
                'status' => 'pending',
                'planned_date' => $later,
                'notes' => 'Follow-up after parts arrive',
            ]);
            $planned->statusHistory()->create([
                'user_id' => $callCenter->id,
                'created_by' => $callCenter->id,
                'from_status' => null,
                'to_status' => 'pending',
                'reason' => 'Order created',
            ]);

            $comments = app(CommentService::class);
            $comments->create($assigned, $dispatcher, 'Please check the rooftop unit first.', 'text', []);
            $comments->create($assigned, $callCenter, 'Customer asked to call before arrival.', 'text', []);
        }

        Auth::logout();
    }
}
