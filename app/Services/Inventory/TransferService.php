<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\JournalPoster;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransferService
{
    public function __construct(
        private InventoryService $inventory,
        private JournalPoster $journals,
    ) {}

    public function create(Warehouse $from, Warehouse $to, User $user, array $lines, ?string $notes = null): WarehouseTransfer
    {
        $this->assertDifferentWarehouses($from, $to);

        return DB::transaction(function () use ($from, $to, $user, $lines, $notes) {
            $transfer = WarehouseTransfer::query()->create([
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'created_by' => $user->id,
                'status' => 'draft',
                'notes' => $notes,
            ]);
            $this->syncLines($transfer, $lines);

            return $transfer->load('lines.item');
        });
    }

    public function update(WarehouseTransfer $transfer, Warehouse $from, Warehouse $to, array $lines, ?string $notes = null): WarehouseTransfer
    {
        if ($transfer->status !== 'draft') {
            throw new InvalidArgumentException('Only draft transfers can be edited.');
        }
        $this->assertDifferentWarehouses($from, $to);

        return DB::transaction(function () use ($transfer, $from, $to, $lines, $notes) {
            $transfer->update([
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'notes' => $notes,
            ]);
            $this->syncLines($transfer, $lines);

            return $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function send(WarehouseTransfer $transfer, User $user): WarehouseTransfer
    {
        if ($transfer->status !== 'draft') {
            throw new InvalidArgumentException('Only draft transfers can be sent.');
        }

        return DB::transaction(function () use ($transfer, $user) {
            $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
            $total = 0;

            foreach ($transfer->lines as $line) {
                $result = $this->inventory->issue(
                    $transfer->fromWarehouse,
                    $line->item,
                    $line->qty,
                    'transfer_out',
                    $transfer,
                );
                $line->unit_cost = $result['unit_cost'];
                $line->save();
                $total += $this->journals->money($result['total_cost']);
            }

            if ($total > 0) {
                $this->journals->post('warehouse_transfer', $transfer->id, 'send', 'Transfer send', [
                    ['account_id' => $this->journals->account('1210')->id, 'debit' => $total, 'credit' => 0],
                    ['account_id' => $this->journals->account('1200')->id, 'debit' => 0, 'credit' => $total],
                ], $user);
            }

            $transfer->status = 'in_transit';
            $transfer->save();

            return $transfer->fresh(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function receive(WarehouseTransfer $transfer, User $user): WarehouseTransfer
    {
        if ($transfer->status !== 'in_transit') {
            throw new InvalidArgumentException('Only in-transit transfers can be received.');
        }

        $transfer->loadMissing('toWarehouse');
        $user->loadMissing('warehouse');
        $this->assertCanReceive($transfer, $user);

        return DB::transaction(function () use ($transfer, $user) {
            $transfer->load(['lines.item', 'fromWarehouse', 'toWarehouse']);
            $total = 0;

            foreach ($transfer->lines as $line) {
                $result = $this->inventory->receive(
                    $transfer->toWarehouse,
                    $line->item,
                    $line->qty,
                    $line->unit_cost,
                    'transfer_in',
                    $transfer,
                );
                $total += $this->journals->money($result['total_cost']);
            }

            if ($total > 0) {
                $this->journals->post('warehouse_transfer', $transfer->id, 'receive', 'Transfer receive', [
                    ['account_id' => $this->journals->account('1200')->id, 'debit' => $total, 'credit' => 0],
                    ['account_id' => $this->journals->account('1210')->id, 'debit' => 0, 'credit' => $total],
                ], $user);
            }

            $transfer->status = 'completed';
            $transfer->received_by = $user->id;
            $transfer->save();

            return $transfer->fresh(['lines.item', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function cancel(WarehouseTransfer $transfer, User $user): WarehouseTransfer
    {
        if ($transfer->status === 'completed') {
            throw new InvalidArgumentException('Completed transfers cannot be cancelled.');
        }

        return DB::transaction(function () use ($transfer, $user) {
            if ($transfer->status === 'in_transit') {
                $transfer->load(['lines.item', 'fromWarehouse']);
                foreach ($transfer->lines as $line) {
                    $this->inventory->receive(
                        $transfer->fromWarehouse,
                        $line->item,
                        $line->qty,
                        $line->unit_cost,
                        'transfer_cancel',
                        $transfer,
                    );
                }
                $this->journals->reverse('warehouse_transfer', $transfer->id, 'send', 'cancel', $user);
            }

            $transfer->status = 'cancelled';
            $transfer->save();

            return $transfer->fresh(['lines.item']);
        });
    }

    public function destroy(WarehouseTransfer $transfer): void
    {
        if ($transfer->status !== 'draft') {
            throw new InvalidArgumentException('Only draft transfers can be deleted.');
        }

        $transfer->delete();
    }

    private function assertCanReceive(WarehouseTransfer $transfer, User $user): void
    {
        $to = $transfer->toWarehouse;
        if (! $to) {
            throw new InvalidArgumentException('Destination warehouse is missing.');
        }

        if ($to->isTechnicianWarehouse()) {
            if ((int) $to->technician_id !== (int) $user->id) {
                throw new InvalidArgumentException('Only the assigned technician can receive this transfer.');
            }

            return;
        }

        if (! $user->canSeeAllStock() && $user->hasPermission('inventory.view_own')) {
            $vanId = $user->warehouse?->id;
            if (! $vanId || (int) $to->id !== (int) $vanId) {
                throw new InvalidArgumentException('You can only receive transfers into your van.');
            }
        }
    }

    private function assertDifferentWarehouses(Warehouse $from, Warehouse $to): void
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Source and destination warehouses must differ.');
        }
    }

    private function syncLines(WarehouseTransfer $transfer, array $lines): void
    {
        $transfer->lines()->delete();

        foreach ($lines as $line) {
            $item = Item::query()->findOrFail($line['item_id']);
            if (! $item->isTracked()) {
                throw new InvalidArgumentException('Transfers only apply to tracked parts.');
            }
            $transfer->lines()->create([
                'item_id' => $item->id,
                'qty' => $line['qty'],
                'created_by' => $transfer->created_by,
            ]);
        }
    }
}
