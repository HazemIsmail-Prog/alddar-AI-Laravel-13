<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\TransferService;
use Illuminate\Http\Request;

class StockFlowController extends Controller
{
    public function transfers(Request $request)
    {
        $user = $request->user()->loadMissing('warehouse');
        $q = WarehouseTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'lines.item', 'creator', 'receiver'])
            ->latest('id');

        if (! $user->canSeeAllStock() && $user->hasPermission('inventory.view_own')) {
            $vanId = $user->warehouse?->id;
            if (! $vanId) {
                return [];
            }
            $q->where(function ($query) use ($vanId) {
                $query->where('from_warehouse_id', $vanId)
                    ->orWhere('to_warehouse_id', $vanId);
            });
        }

        return $q->get();
    }

    public function storeTransfer(Request $request, TransferService $transfers)
    {
        $data = $this->validatedTransfer($request);

        return $this->domain(fn () => $transfers->create(
            Warehouse::query()->findOrFail($data['from_warehouse_id']),
            Warehouse::query()->findOrFail($data['to_warehouse_id']),
            $request->user(),
            $data['lines'],
            $data['notes'] ?? null,
        ));
    }

    public function updateTransfer(Request $request, WarehouseTransfer $transfer, TransferService $transfers)
    {
        $data = $this->validatedTransfer($request);

        return $this->domain(fn () => $transfers->update(
            $transfer,
            Warehouse::query()->findOrFail($data['from_warehouse_id']),
            Warehouse::query()->findOrFail($data['to_warehouse_id']),
            $data['lines'],
            $data['notes'] ?? null,
        ));
    }

    public function sendTransfer(Request $request, WarehouseTransfer $transfer, TransferService $transfers)
    {
        return $this->domain(fn () => $transfers->send($transfer, $request->user()));
    }

    public function receiveTransfer(Request $request, WarehouseTransfer $transfer, TransferService $transfers)
    {
        return $this->domain(fn () => $transfers->receive($transfer, $request->user()));
    }

    public function cancelTransfer(Request $request, WarehouseTransfer $transfer, TransferService $transfers)
    {
        return $this->domain(fn () => $transfers->cancel($transfer, $request->user()));
    }

    public function destroyTransfer(WarehouseTransfer $transfer, TransferService $transfers)
    {
        return $this->domain(function () use ($transfer, $transfers) {
            $transfers->destroy($transfer);

            return response()->noContent();
        });
    }

    public function adjustments()
    {
        return StockAdjustment::query()->with(['warehouse', 'lines.item', 'creator'])->latest('id')->get();
    }

    public function storeAdjustment(Request $request, AdjustmentService $adjustments)
    {
        $data = $this->validatedAdjustment($request);

        return $this->domain(fn () => $adjustments->create(
            Warehouse::query()->findOrFail($data['warehouse_id']),
            $request->user(),
            $data['reason'],
            $data['lines'],
        ));
    }

    public function updateAdjustment(Request $request, StockAdjustment $adjustment, AdjustmentService $adjustments)
    {
        $data = $this->validatedAdjustment($request);

        return $this->domain(fn () => $adjustments->update(
            $adjustment,
            Warehouse::query()->findOrFail($data['warehouse_id']),
            $data['reason'],
            $data['lines'],
        ));
    }

    public function postAdjustment(Request $request, StockAdjustment $adjustment, AdjustmentService $adjustments)
    {
        return $this->domain(fn () => $adjustments->post($adjustment, $request->user()));
    }

    public function destroyAdjustment(StockAdjustment $adjustment, AdjustmentService $adjustments)
    {
        return $this->domain(function () use ($adjustment, $adjustments) {
            $adjustments->destroy($adjustment);

            return response()->noContent();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTransfer(Request $request): array
    {
        return $request->validate([
            'from_warehouse_id' => ['required', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAdjustment(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'reason' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'exists:items,id', 'distinct'],
            'lines.*.qty_delta' => ['required', 'numeric', 'not_in:0', 'decimal:0,2'],
            'lines.*.unit_cost' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
