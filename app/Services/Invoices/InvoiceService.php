<?php

namespace App\Services\Invoices;

use App\Models\AcMachine;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoiceService
{
    public function __construct(
        private InventoryService $inventory,
        private JournalPoster $journals,
    ) {}

    public function createDraft(ServiceOrder $order, User $actor, array $lines, ?string $report = null): Invoice
    {
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Cannot invoice a completed or cancelled order.');
        }

        $draft = $order->invoices()->where('status', 'draft')->first();
        if ($draft) {
            throw new InvalidArgumentException('Order already has a draft invoice.');
        }

        $report = is_string($report) ? trim($report) : null;
        $warehouse = $this->stockWarehouse($order, $actor);

        return DB::transaction(function () use ($order, $actor, $lines, $warehouse, $report) {
            $invoice = Invoice::query()->create([
                'order_id' => $order->id,
                'created_by' => $actor->id,
                'status' => 'draft',
                'report' => $report !== '' ? $report : null,
            ]);

            foreach ($lines as $line) {
                $this->addLine($invoice, $order, $warehouse, $line);
            }

            return $this->recalculate($invoice->fresh('items.item'));
        });
    }

    public function updateDraft(Invoice $invoice, array $payload, User $actor): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException('Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($invoice, $payload, $actor) {
            $invoice->load(['order.technician.warehouse', 'order.contract.machines', 'items.item']);
            $order = $invoice->order;
            $warehouse = $this->stockWarehouse($order, $actor);

            if (array_key_exists('items', $payload)) {
                $priorAmount = [];
                foreach ($invoice->items as $old) {
                    $key = $old->item_id ? 'item:'.$old->item_id : 'text:'.mb_strtolower(trim((string) $old->description));
                    $priorAmount[$key] = $old->unit_amount;
                    if ($old->item?->isTracked() && $warehouse) {
                        $this->inventory->receive(
                            $warehouse,
                            $old->item,
                            $old->quantity,
                            $old->unit_cost,
                            'invoice_restore',
                            $invoice,
                        );
                    }
                }
                $invoice->items()->delete();

                foreach ($payload['items'] as $line) {
                    $this->addLine($invoice, $order, $warehouse, $line, $priorAmount);
                }
            } else {
                foreach ($invoice->items as $itemLine) {
                    if (isset($payload['unit_amounts'][$itemLine->id])) {
                        $itemLine->unit_amount = $payload['unit_amounts'][$itemLine->id];
                        $itemLine->save();
                    }
                }
            }

            if (array_key_exists('discount', $payload)) {
                $invoice->discount = $payload['discount'];
            }

            if (array_key_exists('report', $payload)) {
                $report = is_string($payload['report']) ? trim($payload['report']) : null;
                $invoice->report = $report !== '' ? $report : null;
                $invoice->save();
            }

            return $this->recalculate($invoice->fresh('items.item'));
        });
    }

    public function confirm(Invoice $invoice, User $dispatcher): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidArgumentException('Only draft invoices can be confirmed.');
        }

        return DB::transaction(function () use ($invoice, $dispatcher) {
            $invoice->load(['items.item', 'order.contract', 'order.client']);
            $this->recalculate($invoice);

            $invoice->status = 'confirmed';
            $invoice->confirmed_by = $dispatcher->id;
            $invoice->confirmed_at = now();
            $invoice->save();

            $this->postConfirmJournal($invoice, $dispatcher);

            return $invoice->fresh(['items.item', 'order']);
        });
    }

    public function delete(Invoice $invoice, User $actor): void
    {
        $invoice->load(['order', 'allocations', 'items.item']);

        if ($invoice->status === 'confirmed' && $invoice->allocations->isNotEmpty()) {
            throw new InvalidArgumentException('Cannot delete an invoice that has payments.');
        }

        $order = $invoice->order;
        if ($order && $order->status === 'completed' && $invoice->status !== 'voided') {
            $hasOther = $order->invoices()
                ->where('id', '!=', $invoice->id)
                ->where('status', '!=', 'voided')
                ->exists();
            if (! $hasOther) {
                throw new InvalidArgumentException('Cannot delete the last invoice on a completed order.');
            }
        }

        DB::transaction(function () use ($invoice, $actor) {
            if (in_array($invoice->status, ['draft', 'confirmed'], true)) {
                $this->restoreIssuedStock($invoice, $actor);
            }
            if ($invoice->status === 'confirmed') {
                $this->journals->reverse('invoice', $invoice->id, 'confirm', 'delete', $actor);
            }
            $invoice->delete();
        });
    }

    public function voidDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $this->restoreIssuedStock($invoice);
            $invoice->status = 'voided';
            $invoice->save();
        });
    }

    private function restoreIssuedStock(Invoice $invoice, ?User $actor = null): void
    {
        $invoice->loadMissing(['items.item', 'order.technician.warehouse']);
        $warehouse = $this->stockWarehouse($invoice->order, $actor);
        if (! $warehouse) {
            return;
        }

        foreach ($invoice->items as $line) {
            if ($line->item?->isTracked()) {
                $this->inventory->receive(
                    $warehouse,
                    $line->item,
                    $line->quantity,
                    $line->unit_cost,
                    'invoice_void',
                    $invoice,
                );
            }
        }
    }

    private function stockWarehouse(ServiceOrder $order, ?User $actor = null): ?Warehouse
    {
        $order->loadMissing('technician.warehouse');

        return $order->technician?->warehouse
            ?? $actor?->warehouse
            ?? Warehouse::query()->where('type', 'central')->orderBy('id')->first();
    }

    private function addLine(Invoice $invoice, ServiceOrder $order, ?Warehouse $warehouse, array $line, array $priorAmount = []): void
    {
        $order->loadMissing(['contract.machines']);
        $itemId = $line['item_id'] ?? null;
        $itemId = $itemId ? (int) $itemId : null;
        $description = is_string($line['description'] ?? null) ? trim($line['description']) : '';
        $qty = $line['quantity'];
        $machineId = $this->lineMachineId($line);
        $this->assertMachineOnOrder($machineId, $order);
        $requested = array_key_exists('is_covered', $line) ? (bool) $line['is_covered'] : null;

        if ($itemId) {
            $item = Item::query()->findOrFail($itemId);
            $this->assertItemForOrder($item, $order);
            $unitCost = (int) $item->cost;
            if ($item->isTracked()) {
                if (! $warehouse) {
                    throw new InvalidArgumentException('A warehouse is required to invoice tracked parts.');
                }
                $result = $this->inventory->issue($warehouse, $item, $qty, 'invoice', $invoice);
                $unitCost = $result['unit_cost'];
            }
            $invoice->items()->create([
                'item_id' => $item->id,
                'description' => null,
                'machine_id' => $machineId,
                'quantity' => $qty,
                'unit_amount' => $line['unit_amount'] ?? $priorAmount['item:'.$item->id] ?? $item->default_price,
                'unit_cost' => $unitCost,
                'is_covered' => $this->resolveCovered($order, $item, $machineId, $requested),
                'created_by' => $invoice->created_by,
            ]);

            return;
        }

        if ($description === '') {
            throw new InvalidArgumentException('Each line needs a catalog item or a description.');
        }

        $invoice->items()->create([
            'item_id' => null,
            'description' => $description,
            'machine_id' => $machineId,
            'quantity' => $qty,
            'unit_amount' => $line['unit_amount'] ?? $priorAmount['text:'.mb_strtolower($description)] ?? 0,
            'unit_cost' => 0,
            'is_covered' => $this->resolveCovered($order, null, $machineId, $requested),
            'created_by' => $invoice->created_by,
        ]);
    }

    private function assertItemForOrder(Item $item, ServiceOrder $order): void
    {
        if (! $item->isSellable()) {
            throw new InvalidArgumentException('This item cannot be added to an invoice.');
        }
        if (! $item->availableForDepartment($order->department_id)) {
            throw new InvalidArgumentException('This item is not available for the order department.');
        }
    }

    private function isCovered(?Contract $contract, Item $item, ServiceOrder $order): bool
    {
        if (! $contract || ! $item->isSellable()) {
            return false;
        }

        if ($item->type === 'service') {
            return true;
        }

        if ($item->isTracked()) {
            return $contract->type === 'warranty' || (bool) $contract->includes_spare_parts;
        }

        return false;
    }

    private function lineMachineId(array $line): ?int
    {
        if (! array_key_exists('machine_id', $line) || $line['machine_id'] === null || $line['machine_id'] === '') {
            return null;
        }

        $id = (int) $line['machine_id'];

        return $id > 0 ? $id : null;
    }

    private function assertMachineOnOrder(?int $machineId, ServiceOrder $order): void
    {
        if (! $machineId) {
            return;
        }

        $onLocation = AcMachine::query()
            ->where('id', $machineId)
            ->where('location_id', $order->location_id)
            ->exists();
        if (! $onLocation) {
            throw new InvalidArgumentException('The machine must belong to the order location.');
        }
    }

    private function machineAllowsCoverage(?Contract $contract, ?int $machineId): bool
    {
        if (! $contract) {
            return false;
        }
        if (! $machineId) {
            return true;
        }

        $ids = $contract->relationLoaded('machines')
            ? $contract->machines->pluck('id')
            : $contract->machines()->pluck('ac_machines.id');

        return $ids->contains($machineId);
    }

    private function resolveCovered(ServiceOrder $order, ?Item $item, ?int $machineId, ?bool $requested): bool
    {
        if (! $this->machineAllowsCoverage($order->contract, $machineId)) {
            return false;
        }
        if ($requested !== null) {
            return $requested;
        }
        if (! $item) {
            return false;
        }

        return $this->isCovered($order->contract, $item, $order);
    }

    public function recalculate(Invoice $invoice): Invoice
    {
        $invoice->load('items');
        $subtotal = 0;
        $cost = 0;
        foreach ($invoice->items as $line) {
            $cost += $line->lineCost();
            if (! $line->is_covered) {
                $subtotal += $line->lineTotal();
            }
        }
        $discount = $this->journals->money($invoice->discount);
        $total = $subtotal - $discount;
        if ($total < 0) {
            $total = 0;
        }
        $invoice->subtotal = $subtotal;
        $invoice->total = $total;
        $invoice->contract_cost = $cost;
        $invoice->save();

        return $invoice;
    }

    private function postConfirmJournal(Invoice $invoice, User $user): void
    {
        $ar = $this->journals->account('1100');
        $serviceRev = $this->journals->account('4000');
        $partsRev = $this->journals->account('4010');
        $discountAcc = $this->journals->account('5100');
        $cogs = $this->journals->account('5000');
        $fulfill = $this->journals->account('5010');
        $inventory = $this->journals->account('1200');

        $coveredCogs = 0;
        $uncoveredCogs = 0;
        $serviceRevenue = 0;
        $partsRevenue = 0;

        foreach ($invoice->items as $line) {
            $lineCost = $this->journals->money($line->lineCost());
            $lineRev = $line->is_covered ? 0 : $this->journals->money($line->lineTotal());
            if ($line->item?->isTracked()) {
                if ($line->is_covered) {
                    $coveredCogs += $lineCost;
                } else {
                    $uncoveredCogs += $lineCost;
                }
            }
            if ($lineRev > 0) {
                if ($line->item?->type === 'service') {
                    $serviceRevenue += $lineRev;
                } else {
                    $partsRevenue += $lineRev;
                }
            }
        }

        $discount = $this->journals->money($invoice->discount);
        $arAmount = $this->journals->money($invoice->total);

        $lines = [];
        if ($arAmount > 0) {
            $lines[] = ['account_id' => $ar->id, 'debit' => $arAmount, 'credit' => 0];
        }
        if ($discount > 0) {
            $lines[] = ['account_id' => $discountAcc->id, 'debit' => $discount, 'credit' => 0];
        }
        if ($serviceRevenue > 0) {
            $lines[] = ['account_id' => $serviceRev->id, 'debit' => 0, 'credit' => $serviceRevenue];
        }
        if ($partsRevenue > 0) {
            $lines[] = ['account_id' => $partsRev->id, 'debit' => 0, 'credit' => $partsRevenue];
        }
        if ($uncoveredCogs > 0) {
            $lines[] = ['account_id' => $cogs->id, 'debit' => $uncoveredCogs, 'credit' => 0];
            $lines[] = ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $uncoveredCogs];
        }
        if ($coveredCogs > 0) {
            $lines[] = ['account_id' => $fulfill->id, 'debit' => $coveredCogs, 'credit' => 0];
            $lines[] = ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $coveredCogs];
        }

        if ($lines !== []) {
            $this->journals->post('invoice', $invoice->id, 'confirm', 'Invoice '.$invoice->id.' confirm', $lines, $user);
        }
    }
}
