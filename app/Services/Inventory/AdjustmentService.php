<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\JournalPoster;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdjustmentService
{
    public function __construct(
        private InventoryService $inventory,
        private JournalPoster $journals,
    ) {}

    public function create(Warehouse $warehouse, User $user, string $reason, array $lines): StockAdjustment
    {
        $this->assertReason($reason);

        return DB::transaction(function () use ($warehouse, $user, $reason, $lines) {
            $adjustment = StockAdjustment::query()->create([
                'warehouse_id' => $warehouse->id,
                'created_by' => $user->id,
                'status' => 'draft',
                'reason' => $reason,
            ]);
            $this->syncLines($adjustment, $lines);

            return $adjustment->load('lines.item');
        });
    }

    public function update(StockAdjustment $adjustment, Warehouse $warehouse, string $reason, array $lines): StockAdjustment
    {
        if ($adjustment->status !== 'draft') {
            throw new InvalidArgumentException('Only draft adjustments can be edited.');
        }
        $this->assertReason($reason);

        return DB::transaction(function () use ($adjustment, $warehouse, $reason, $lines) {
            $adjustment->update([
                'warehouse_id' => $warehouse->id,
                'reason' => $reason,
            ]);
            $this->syncLines($adjustment, $lines);

            return $adjustment->load(['lines.item', 'warehouse']);
        });
    }

    public function post(StockAdjustment $adjustment, User $user): StockAdjustment
    {
        if ($adjustment->status !== 'draft') {
            throw new InvalidArgumentException('Only draft adjustments can be posted.');
        }

        return DB::transaction(function () use ($adjustment, $user) {
            $adjustment->load(['lines.item', 'warehouse']);
            $increaseValue = 0;
            $decreaseValue = 0;

            foreach ($adjustment->lines as $line) {
                $delta = (string) $line->qty_delta;
                if (bccomp($delta, '0', 2) > 0) {
                    if ($line->unit_cost === null) {
                        throw new InvalidArgumentException('Unit cost is required for quantity increases.');
                    }
                    $result = $this->inventory->receive(
                        $adjustment->warehouse,
                        $line->item,
                        $delta,
                        $line->unit_cost,
                        'adjustment',
                        $adjustment,
                    );
                    $increaseValue += $this->journals->money($result['total_cost']);
                } elseif (bccomp($delta, '0', 2) < 0) {
                    $result = $this->inventory->issue(
                        $adjustment->warehouse,
                        $line->item,
                        bcmul($delta, '-1', 2),
                        'adjustment',
                        $adjustment,
                    );
                    $line->unit_cost = $result['unit_cost'];
                    $line->save();
                    $decreaseValue += $this->journals->money($result['total_cost']);
                }
            }

            $inventory = $this->journals->account('1200');
            $adj = $this->journals->account('5200');

            if ($increaseValue > 0) {
                $this->journals->post('stock_adjustment', $adjustment->id, 'increase', 'Stock adjustment increase', [
                    ['account_id' => $inventory->id, 'debit' => $increaseValue, 'credit' => 0],
                    ['account_id' => $adj->id, 'debit' => 0, 'credit' => $increaseValue],
                ], $user);
            }

            if ($decreaseValue > 0) {
                $this->journals->post('stock_adjustment', $adjustment->id, 'decrease', 'Stock adjustment decrease', [
                    ['account_id' => $adj->id, 'debit' => $decreaseValue, 'credit' => 0],
                    ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $decreaseValue],
                ], $user);
            }

            $adjustment->status = 'posted';
            $adjustment->save();

            return $adjustment->fresh(['lines.item', 'warehouse']);
        });
    }

    public function destroy(StockAdjustment $adjustment): void
    {
        if ($adjustment->status !== 'draft') {
            throw new InvalidArgumentException('Only draft adjustments can be deleted.');
        }

        $adjustment->delete();
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Adjustment reason is required.');
        }
    }

    private function syncLines(StockAdjustment $adjustment, array $lines): void
    {
        $adjustment->lines()->delete();

        foreach ($lines as $line) {
            $item = Item::query()->findOrFail($line['item_id']);
            if (! $item->isTracked()) {
                throw new InvalidArgumentException('Adjustments only apply to tracked parts.');
            }
            $adjustment->lines()->create([
                'item_id' => $item->id,
                'qty_delta' => $line['qty_delta'],
                'unit_cost' => $line['unit_cost'] ?? null,
                'created_by' => $adjustment->created_by,
            ]);
        }
    }
}
