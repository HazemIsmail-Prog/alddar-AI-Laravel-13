<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockLayer;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    /**
     * @return array{qty: string, unit_cost: int, total_cost: int}
     */
    public function receive(
        Warehouse $warehouse,
        Item $item,
        mixed $qty,
        mixed $unitCost,
        string $type = 'receipt',
        ?Model $source = null,
    ): array {
        if (! $item->isTracked()) {
            throw new InvalidArgumentException('Only tracked parts can move in inventory.');
        }

        $qty = $this->qty($qty);
        $unitCost = (int) $unitCost;
        if (bccomp($qty, '0', 2) <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $item, $qty, $unitCost, $type, $source) {
            $createdBy = $this->actorId($source);
            $level = $this->lockLevel($warehouse->id, $item->id, $createdBy);
            $total = $this->moneyFromQty($qty, $unitCost);

            if ($item->valuation_method === 'fifo') {
                StockLayer::query()->create([
                    'warehouse_id' => $warehouse->id,
                    'item_id' => $item->id,
                    'qty_remaining' => $qty,
                    'unit_cost' => $unitCost,
                    'received_at' => now(),
                    'created_by' => $createdBy,
                ]);
                $level->qty = bcadd($this->qty($level->qty), $qty, 2);
                $level->average_cost = $this->fifoAverage($warehouse->id, $item->id);
                $level->save();
            } else {
                $oldQty = $this->qty($level->qty);
                $oldValue = $this->moneyFromQty($oldQty, (int) $level->average_cost);
                $newQty = bcadd($oldQty, $qty, 2);
                $level->qty = $newQty;
                $level->average_cost = $this->avgCost($oldValue + $total, $newQty);
                $level->save();
            }

            $this->recordMovement($warehouse, $item, $qty, $unitCost, $total, $type, $source);

            return ['qty' => $qty, 'unit_cost' => $unitCost, 'total_cost' => $total];
        });
    }

    /**
     * @return array{qty: string, unit_cost: int, total_cost: int}
     */
    public function issue(
        Warehouse $warehouse,
        Item $item,
        mixed $qty,
        string $type = 'issue',
        ?Model $source = null,
    ): array {
        if (! $item->isTracked()) {
            throw new InvalidArgumentException('Only tracked parts can move in inventory.');
        }

        $qty = $this->qty($qty);
        if (bccomp($qty, '0', 2) <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $item, $qty, $type, $source) {
            $createdBy = $this->actorId($source);
            $level = $this->lockLevel($warehouse->id, $item->id, $createdBy);
            if (bccomp($this->qty($level->qty), $qty, 2) < 0) {
                throw new InvalidArgumentException("Insufficient stock for {$item->sku} in {$warehouse->name}.");
            }

            if ($item->valuation_method === 'fifo') {
                $remaining = $qty;
                $total = 0;
                $layers = StockLayer::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('item_id', $item->id)
                    ->where('qty_remaining', '>', 0)
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($layers as $layer) {
                    if (bccomp($remaining, '0', 2) <= 0) {
                        break;
                    }
                    $layerQty = $this->qty($layer->qty_remaining);
                    $take = bccomp($layerQty, $remaining, 2) <= 0 ? $layerQty : $remaining;
                    $total += $this->moneyFromQty($take, (int) $layer->unit_cost);
                    $layer->qty_remaining = bcsub($layerQty, $take, 2);
                    $layer->save();
                    $remaining = bcsub($remaining, $take, 2);
                }

                if (bccomp($remaining, '0', 2) > 0) {
                    throw new InvalidArgumentException("Insufficient FIFO layers for {$item->sku}.");
                }

                $unitCost = $this->avgCost($total, $qty);
                $level->qty = bcsub($this->qty($level->qty), $qty, 2);
                $level->average_cost = $this->fifoAverage($warehouse->id, $item->id);
                $level->save();
            } else {
                $unitCost = (int) $level->average_cost;
                $total = $this->moneyFromQty($qty, $unitCost);
                $level->qty = bcsub($this->qty($level->qty), $qty, 2);
                $level->save();
            }

            $this->recordMovement($warehouse, $item, bcmul($qty, '-1', 2), $unitCost, -$total, $type, $source);

            return ['qty' => $qty, 'unit_cost' => $unitCost, 'total_cost' => $total];
        });
    }

    public function onHand(int $warehouseId, int $itemId): string
    {
        return $this->qty(StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->value('qty') ?? 0);
    }

    public function moneyFromQty(mixed $qty, mixed $unitCost): int
    {
        $product = bcmul($this->qty($qty), (string) (int) $unitCost, 2);

        if (bccomp($product, '0', 2) >= 0) {
            return (int) bcadd($product, '0.5', 0);
        }

        return (int) bcsub($product, '0.5', 0);
    }

    public function valuationRows(): array
    {
        $onHand = StockLevel::query()
            ->with(['warehouse', 'item'])
            ->where('qty', '>', 0)
            ->get()
            ->map(fn (StockLevel $level) => [
                'warehouse_id' => $level->warehouse_id,
                'warehouse' => $level->warehouse->name,
                'item_id' => $level->item_id,
                'sku' => $level->item->sku,
                'item' => $level->item->name,
                'method' => $level->item->valuation_method,
                'qty' => $this->qty($level->qty),
                'unit_cost' => (int) $level->average_cost,
                'value' => $this->moneyFromQty($level->qty, $level->average_cost),
                'in_transit' => false,
            ]);

        $inTransit = \App\Models\WarehouseTransfer::query()
            ->with(['fromWarehouse', 'lines.item'])
            ->where('status', 'in_transit')
            ->get()
            ->flatMap(function ($transfer) {
                return $transfer->lines->map(fn ($line) => [
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'warehouse' => 'In transit: '.$transfer->fromWarehouse->name,
                    'item_id' => $line->item_id,
                    'sku' => $line->item->sku,
                    'item' => $line->item->name,
                    'method' => $line->item->valuation_method,
                    'qty' => $this->qty($line->qty),
                    'unit_cost' => (int) ($line->unit_cost ?? 0),
                    'value' => $this->moneyFromQty($line->qty, $line->unit_cost ?? 0),
                    'in_transit' => true,
                ]);
            });

        return $onHand->concat($inTransit)->values()->all();
    }

    public function onHandTotal(): int
    {
        return (int) collect($this->valuationRows())
            ->reject(fn ($row) => $row['in_transit'])
            ->sum('value');
    }

    public function inTransitTotal(): int
    {
        return (int) collect($this->valuationRows())
            ->filter(fn ($row) => $row['in_transit'])
            ->sum('value');
    }

    private function lockLevel(int $warehouseId, int $itemId, ?int $createdBy = null): StockLevel
    {
        $level = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if (! $level) {
            $level = StockLevel::query()->create([
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'qty' => '0.0000',
                'average_cost' => 0,
                'created_by' => $createdBy ?? $this->actorId(),
            ]);
        }

        return $level;
    }

    private function fifoAverage(int $warehouseId, int $itemId): int
    {
        $layers = StockLayer::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('qty_remaining', '>', 0)
            ->get();

        $qty = '0.0000';
        $value = 0;
        foreach ($layers as $layer) {
            $layerQty = $this->qty($layer->qty_remaining);
            $qty = bcadd($qty, $layerQty, 2);
            $value += $this->moneyFromQty($layerQty, (int) $layer->unit_cost);
        }

        return $this->avgCost($value, $qty);
    }

    private function avgCost(int $value, string $qty): int
    {
        if (bccomp($qty, '0', 2) === 0) {
            return 0;
        }

        $avg = bcdiv((string) $value, $qty, 2);

        if (bccomp($avg, '0', 2) >= 0) {
            return (int) bcadd($avg, '0.5', 0);
        }

        return (int) bcsub($avg, '0.5', 0);
    }

    private function recordMovement(
        Warehouse $warehouse,
        Item $item,
        string $qty,
        int $unitCost,
        int $totalCost,
        string $type,
        ?Model $source,
    ): void {
        $movement = new StockMovement([
            'warehouse_id' => $warehouse->id,
            'item_id' => $item->id,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'type' => $type,
            'created_by' => $this->actorId($source),
        ]);
        if ($source) {
            $movement->source()->associate($source);
        }
        $movement->save();
    }

    private function actorId(?Model $source = null): int
    {
        if (auth()->id()) {
            return (int) auth()->id();
        }

        $fromSource = $source?->getAttribute('created_by');
        if ($fromSource) {
            return (int) $fromSource;
        }

        throw new InvalidArgumentException('created_by requires an authenticated user.');
    }

    private function qty(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
