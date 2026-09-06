<?php

namespace Tests\Support;

use App\Models\Department;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\TransferService;
use Illuminate\Support\Facades\Auth;

class DemoInventory
{
    public static function seed(): void
    {
        $admin = User::query()->where('email', 'admin@example.test')->first();
        $central = Warehouse::query()->where('name', 'Central')->first();
        $van1 = Warehouse::query()->where('name', 'Tech van 1')->first();
        $van2 = Warehouse::query()->where('name', 'Tech van 2')->first();
        $maintenance = Department::query()->where('name_en', 'AC Maintenance')->first();
        $install = Department::query()->where('name_en', 'Installation')->first();

        Auth::login($admin);

        Item::reclassifyUntrackedParts();

        $fifoPart = Item::query()->updateOrCreate(['sku' => 'FLT-20'], [
            'name' => 'Air filter 20x20',
            'type' => 'tracked_part',
            'valuation_method' => 'fifo',
            'cost' => 8,
            'default_price' => 18,
            'department_id' => $maintenance->id,
        ]);
        $wacPart = Item::query()->updateOrCreate(['sku' => 'GAS-R410'], [
            'name' => 'R410A refrigerant (lb)',
            'type' => 'tracked_part',
            'valuation_method' => 'weighted_average',
            'cost' => 12,
            'default_price' => 28,
            'department_id' => $maintenance->id,
        ]);
        $compressor = Item::query()->updateOrCreate(['sku' => 'CMP-5T'], [
            'name' => '5-ton compressor',
            'type' => 'tracked_part',
            'valuation_method' => 'fifo',
            'cost' => 420,
            'default_price' => 890,
            'department_id' => $install->id,
        ]);
        Item::query()->updateOrCreate(['sku' => 'SVC-VISIT'], [
            'name' => 'Service visit',
            'type' => 'service',
            'valuation_method' => null,
            'cost' => 25,
            'default_price' => 80,
            'department_id' => $maintenance->id,
        ]);
        $cleaner = Item::query()->updateOrCreate(['sku' => 'CLN-COIL'], [
            'name' => 'Coil cleaner',
            'type' => 'tracked_part',
            'valuation_method' => 'fifo',
            'cost' => 4,
            'default_price' => 12,
            'department_id' => null,
        ]);
        $pump = Item::query()->updateOrCreate(['sku' => 'VAC-PUMP'], [
            'name' => 'Vacuum pump',
            'type' => 'tracked_part',
            'valuation_method' => 'fifo',
            'cost' => 95,
            'default_price' => 0,
            'is_sellable' => false,
            'department_id' => $maintenance->id,
        ]);

        $inventory = app(InventoryService::class);
        $journals = app(JournalPoster::class);

        $receipts = [
            [$central, $fifoPart, 40, 8],
            [$central, $wacPart, 30, 12],
            [$central, $compressor, 4, 420],
            [$van1, $fifoPart, 6, 8],
            [$van1, $wacPart, 8, 12],
            [$van1, $cleaner, 10, 4],
            [$central, $pump, 2, 95],
            [$van1, $pump, 1, 95],
        ];

        foreach ($receipts as [$wh, $item, $qty, $cost]) {
            if (bccomp($inventory->onHand($wh->id, $item->id), '0', 4) > 0) {
                continue;
            }
            $result = $inventory->receive($wh, $item, $qty, $cost, 'opening');
            $value = $journals->money($result['total_cost']);
            $journals->post('opening_stock', $wh->id * 1000 + $item->id, 'opening', 'Opening stock', [
                ['account_id' => $journals->account('1200')->id, 'debit' => $value, 'credit' => 0],
                ['account_id' => $journals->account('3000')->id, 'debit' => 0, 'credit' => $value],
            ], $admin);
        }

        if (WarehouseTransfer::query()->count() === 0) {
            app(TransferService::class)->create(
                $central,
                $van2,
                $admin,
                [['item_id' => $fifoPart->id, 'qty' => 4]],
                'Restock van 2',
            );
        }

        if (StockAdjustment::query()->count() === 0) {
            $adj = app(AdjustmentService::class)->create(
                $central,
                $admin,
                'Cycle count correction',
                [['item_id' => $wacPart->id, 'qty_delta' => 2, 'unit_cost' => 12]],
            );
            app(AdjustmentService::class)->post($adj, $admin);
        }

        Auth::logout();
    }
}
