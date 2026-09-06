<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function items(Request $request)
    {
        Item::reclassifyUntrackedParts();
        $q = Item::query()->with('department')->latest('id');
        $filters = $request->validate([
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'sellable' => ['sometimes', 'boolean'],
        ]);
        if (isset($filters['department_id'])) {
            $departmentId = $filters['department_id'];
            $q->where(fn ($query) => $query
                ->whereNull('department_id')
                ->orWhere('department_id', $departmentId));
        }
        if ($request->boolean('sellable')) {
            $q->where('is_sellable', true);
        }

        return $q->get();
    }

    public function storeItem(Request $request)
    {
        $request->merge(['department_id' => $request->input('department_id') ?: null]);
        $data = $request->validate([
            'sku' => ['required', 'unique:items,sku'],
            'name' => ['required'],
            'type' => ['required', Rule::in(['service', 'tracked_part'])],
            'valuation_method' => ['nullable', Rule::in(['weighted_average', 'fifo'])],
            'cost' => ['integer'],
            'default_price' => ['integer'],
            'is_sellable' => ['boolean'],
            'department_id' => ['nullable', Department::serviceIdRule()],
        ]);
        if ($data['type'] === 'tracked_part' && empty($data['valuation_method'])) {
            return response()->json(['message' => 'Tracked parts require a valuation method.'], 422);
        }
        if ($data['type'] !== 'tracked_part') {
            $data['valuation_method'] = null;
        }
        $data['is_sellable'] = $data['is_sellable'] ?? true;
        if (! $data['is_sellable']) {
            $data['default_price'] = 0;
        }

        return Item::query()->create($data)->load('department');
    }

    public function updateItem(Request $request, Item $item)
    {
        if ($request->exists('department_id')) {
            $request->merge(['department_id' => $request->input('department_id') ?: null]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'cost' => ['integer'],
            'default_price' => ['integer'],
            'is_sellable' => ['boolean'],
            'department_id' => ['nullable', Department::serviceIdRule()],
        ]);
        if ($request->filled('valuation_method')
            && $request->string('valuation_method')->toString() !== (string) $item->valuation_method
            && $item->movementsExist()) {
            return response()->json(['message' => 'Valuation method is locked after the first stock movement.'], 422);
        }
        if ($request->has('valuation_method') && ! $item->movementsExist()) {
            $data['valuation_method'] = $request->input('valuation_method');
        }
        if (array_key_exists('is_sellable', $data) && ! $data['is_sellable']) {
            $data['default_price'] = 0;
        }
        $item->update($data);

        return $item->load('department');
    }

    public function destroyItem(Item $item)
    {
        if ($item->movementsExist() || $item->invoiceItems()->exists()) {
            return response()->json(['message' => 'This item has been used and cannot be deleted. Deactivate it by editing instead.'], 422);
        }
        $item->delete();

        return response()->json(['ok' => true]);
    }

    public function warehouses()
    {
        return Warehouse::query()->with(['technician', 'stockLevels.item'])->orderBy('name')->get();
    }

    public function storeWarehouse(Request $request)
    {
        $data = $this->validatedWarehouse($request);
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        return Warehouse::query()->create($data)->load('technician');
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse)
    {
        $data = $this->validatedWarehouse($request, $warehouse);
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }
        $warehouse->update($data);

        return $warehouse->fresh()->load('technician');
    }

    public function destroyWarehouse(Warehouse $warehouse)
    {
        $inUse = $warehouse->stockLevels()->where('qty', '!=', 0)->exists()
            || StockMovement::query()->where('warehouse_id', $warehouse->id)->exists()
            || StockAdjustment::query()->where('warehouse_id', $warehouse->id)->exists()
            || WarehouseTransfer::query()
                ->where(fn ($q) => $q
                    ->where('from_warehouse_id', $warehouse->id)
                    ->orWhere('to_warehouse_id', $warehouse->id))
                ->exists();
        if ($inUse) {
            return response()->json(['message' => 'This warehouse has stock or history and cannot be deleted.'], 422);
        }
        $warehouse->delete();

        return response()->json(['ok' => true]);
    }

    private function validatedWarehouse(Request $request, ?Warehouse $warehouse = null): array|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->ignore($warehouse?->id)],
            'type' => ['required', Rule::in(['central', 'technician'])],
            'technician_id' => ['nullable', 'exists:users,id', Rule::unique('warehouses', 'technician_id')->ignore($warehouse?->id)],
        ]);

        if ($data['type'] === 'central') {
            $data['technician_id'] = null;
        } elseif (empty($data['technician_id'])) {
            return response()->json(['message' => 'A van warehouse must be assigned to a technician.'], 422);
        } else {
            $tech = User::query()->findOrFail($data['technician_id']);
            if (! $tech->hasRole('technician')) {
                return response()->json(['message' => 'The assigned user must be a technician.'], 422);
            }
        }

        return $data;
    }

    public function stockLevels(Request $request, InventoryService $inventory)
    {
        $user = $request->user()->loadMissing('warehouse');
        $q = StockLevel::query()
            ->with(['warehouse', 'item'])
            ->orderBy('warehouse_id')
            ->orderBy('item_id');

        if (! $user->canSeeAllStock()) {
            if (! $user->hasPermission('inventory.view_own')) {
                abort(403);
            }
            $vanId = $user->warehouse?->id;
            if (! $vanId) {
                return [];
            }
            $q->where('warehouse_id', $vanId);
        }

        if ($wid = $request->integer('warehouse_id')) {
            $q->where('warehouse_id', $wid);
        }
        if ($iid = $request->integer('item_id')) {
            $q->where('item_id', $iid);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $q->whereHas('item', function ($item) use ($search) {
                $item->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        }
        if (! $request->boolean('include_zero')) {
            $q->where('qty', '>', 0);
        }

        return $q->get()->map(fn (StockLevel $level) => [
            'id' => $level->id,
            'warehouse_id' => $level->warehouse_id,
            'warehouse' => $level->warehouse->name,
            'warehouse_type' => $level->warehouse->type,
            'item_id' => $level->item_id,
            'sku' => $level->item->sku,
            'item' => $level->item->name,
            'qty' => $level->qty,
            'average_cost' => $level->average_cost,
            'value' => $inventory->moneyFromQty($level->qty, $level->average_cost),
        ]);
    }

    public function receive(Request $request, Warehouse $warehouse, InventoryService $inventory, JournalPoster $journals)
    {
        $data = $request->validate([
            'item_id' => ['required', 'exists:items,id'],
            'qty' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'unit_cost' => ['required', 'integer', 'gte:0'],
        ]);

        return $this->domain(function () use ($data, $warehouse, $inventory, $journals, $request) {
            $item = Item::query()->findOrFail($data['item_id']);
            $result = $inventory->receive($warehouse, $item, $data['qty'], $data['unit_cost'], 'receipt');
            $value = $journals->money($result['total_cost']);
            $journals->post('stock_receipt', $warehouse->id * 100000 + $item->id + time() % 100000, 'receipt-'.uniqid(), 'Stock receipt', [
                ['account_id' => $journals->account('1200')->id, 'debit' => $value, 'credit' => 0],
                ['account_id' => $journals->account('2000')->id, 'debit' => 0, 'credit' => $value],
            ], $request->user());

            return $result;
        });
    }

    public function movements(Warehouse $warehouse)
    {
        return StockMovement::query()
            ->with('item')
            ->where('warehouse_id', $warehouse->id)
            ->latest()
            ->limit(100)
            ->get();
    }
}
