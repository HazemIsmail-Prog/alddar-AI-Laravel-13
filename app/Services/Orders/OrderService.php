<?php

namespace App\Services\Orders;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use App\Services\Push\PushService;
use App\Support\StaffRealtime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    public function changeStatus(
        ServiceOrder $order,
        string $to,
        User $user,
        ?string $reason = null,
        array $extra = [],
    ): ServiceOrder {
        $from = $order->status;
        if ($from === $to && $extra === []) {
            return $order;
        }

        $previousTechId = $order->technician_id ? (int) $order->technician_id : null;
        $order->fill($extra);
        $order->status = $to;
        $order->save();

        $order->statusHistory()->create([
            'user_id' => $user->id,
            'technician_id' => $to === 'assigned' && $order->technician_id ? (int) $order->technician_id : null,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'created_by' => $user->id,
        ]);

        $order = $order->fresh(['statusHistory.user', 'statusHistory.technician', 'technician', 'invoices', 'invoice']);
        $notifyTechId = $order->technician_id ? (int) $order->technician_id : $previousTechId;
        StaffRealtime::order($order, $to, $notifyTechId);

        return $order;
    }

    public function assign(ServiceOrder $order, User $technician, User $actor, ?int $sortOrder = null): ServiceOrder
    {
        if (! $technician->hasRole('technician')) {
            throw new InvalidArgumentException('User is not a technician.');
        }

        if (! $technician->departments->contains('id', $order->department_id)) {
            throw new InvalidArgumentException('Technician does not belong to this department.');
        }

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Cannot assign a completed or cancelled order.');
        }

        $sortOrder ??= (int) ServiceOrder::query()
            ->where('technician_id', $technician->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'on_hold'])
            ->max('sort_order') + 1;

        $assigned = $this->changeStatus($order, 'assigned', $actor, null, [
            'technician_id' => $technician->id,
            'sort_order' => $sortOrder,
        ]);
        app(PushService::class)->notifyOrderAssigned($assigned, $technician);

        return $assigned;
    }

    public function reorder(User $technician, array $orderIds): void
    {
        $previousIds = [];
        foreach ($technician->serviceDepartments() as $dept) {
            $current = $this->currentFor($technician, (int) $dept->id);
            if ($current) {
                $previousIds[] = (int) $current->id;
            }
        }

        DB::transaction(function () use ($technician, $orderIds) {
            foreach ($orderIds as $index => $id) {
                ServiceOrder::query()
                    ->where('id', $id)
                    ->where('technician_id', $technician->id)
                    ->update(['sort_order' => $index + 1]);
            }
        });

        foreach (array_unique($previousIds) as $id) {
            $order = ServiceOrder::query()->find($id);
            if ($order) {
                StaffRealtime::order($order, 'updated');
            }
        }
        $this->notifyReordered($orderIds);
    }

    public function reorderQueue(string $queue, array $orderIds, bool $notify = true): void
    {
        if (! in_array($queue, ['unassigned', 'held', 'planned'], true)) {
            throw new InvalidArgumentException('Unknown dispatch queue.');
        }

        DB::transaction(function () use ($queue, $orderIds) {
            foreach ($orderIds as $index => $id) {
                $query = ServiceOrder::query()->where('id', $id);
                if ($queue === 'held') {
                    $query->where('status', 'on_hold');
                } else {
                    $query->whereNull('technician_id')->where('status', '!=', 'on_hold');
                }
                $query->update(['sort_order' => $index + 1]);
            }
        });

        if ($notify) {
            $this->notifyReordered($orderIds);
        }
    }

    public function prependToWaitingQueue(ServiceOrder $order): void
    {
        $future = $order->isPlannedFuture();
        $existing = ServiceOrder::query()
            ->where('department_id', $order->department_id)
            ->whereNull('technician_id')
            ->where('status', '!=', 'on_hold')
            ->where('id', '!=', $order->id)
            ->when(
                $future,
                fn ($q) => $q->whereDate('planned_date', '>', now()->toDateString()),
                fn ($q) => $q->where(function ($inner) {
                    $inner->whereNull('planned_date')
                        ->orWhereDate('planned_date', '<=', now()->toDateString());
                }),
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->reorderQueue($future ? 'planned' : 'unassigned', [$order->id, ...$existing], false);
    }

    public function hold(ServiceOrder $order, User $actor, string $reason, InvoiceService $invoices): ServiceOrder
    {
        $this->assertCanHoldOrCancel($order);
        $this->voidDraftInvoice($order, $invoices);

        return $this->changeStatus($order, 'on_hold', $actor, $reason, [
            'technician_id' => null,
            'sort_order' => null,
            'accepted_at' => null,
            'reached_at' => null,
        ]);
    }

    public function cancel(ServiceOrder $order, User $actor, string $reason, InvoiceService $invoices): ServiceOrder
    {
        $this->assertCanHoldOrCancel($order);
        $this->voidDraftInvoice($order, $invoices);

        return $this->changeStatus($order, 'cancelled', $actor, $reason, [
            'technician_id' => null,
            'sort_order' => null,
            'accepted_at' => null,
            'reached_at' => null,
        ]);
    }

    public function accept(ServiceOrder $order, User $tech): ServiceOrder
    {
        $this->assertCurrentJob($order, $tech);
        if ($order->status !== 'assigned') {
            throw new InvalidArgumentException('Order must be assigned before accept.');
        }

        return $this->changeStatus($order, 'accepted', $tech, null, ['accepted_at' => now()]);
    }

    public function reached(ServiceOrder $order, User $tech): ServiceOrder
    {
        $this->assertCurrentJob($order, $tech);
        if ($order->status !== 'accepted') {
            throw new InvalidArgumentException('Order must be accepted before reached.');
        }

        return $this->changeStatus($order, 'reached', $tech, null, ['reached_at' => now()]);
    }

    public function complete(ServiceOrder $order, User $tech): ServiceOrder
    {
        $this->assertCurrentJob($order, $tech);
        if ($order->status !== 'reached') {
            throw new InvalidArgumentException('Order must be reached before complete.');
        }

        $hasInvoice = $order->invoices()->where('status', '!=', 'voided')->exists();
        if (! $hasInvoice) {
            throw new InvalidArgumentException('Cannot complete without an invoice.');
        }

        return $this->changeStatus($order, 'completed', $tech, null, ['completed_at' => now()]);
    }

    public function currentFor(User $tech, ?int $departmentId = null): ?ServiceOrder
    {
        return ServiceOrder::query()
            ->with(['client.phones', 'phone', 'location.machines', 'department', 'contract.machines', 'invoices.items.item', 'invoice', 'statusHistory.user', 'statusHistory.technician'])
            ->where('technician_id', $tech->id)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->whereNotIn('status', ['completed', 'cancelled', 'on_hold', 'pending'])
            ->where(function ($q) {
                $q->whereNull('planned_date')
                    ->orWhereDate('planned_date', '<=', now()->toDateString());
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function isCurrentJob(User $tech, ServiceOrder $order): bool
    {
        if ((int) $order->technician_id !== (int) $tech->id) {
            return false;
        }

        $current = $this->currentFor($tech, (int) $order->department_id);

        return $current !== null && (int) $current->id === (int) $order->id;
    }

    public function detailsVisibleTo(User $user, ServiceOrder $order): bool
    {
        if (! $user->isFieldTech()) {
            return true;
        }

        return $order->status !== 'assigned';
    }

    /**
     * @return ServiceOrder|array{id: int, status: string, department_id: int|null}
     */
    public function presentFor(User $user, ServiceOrder $order): ServiceOrder|array
    {
        if ($this->detailsVisibleTo($user, $order)) {
            return $order;
        }

        return [
            'id' => $order->id,
            'status' => $order->status,
            'department_id' => $order->department_id,
        ];
    }

    /**
     * @return list<int>
     */
    public function currentIdsFor(User $tech): array
    {
        $ids = [];
        foreach ($tech->serviceDepartments() as $dept) {
            $current = $this->currentFor($tech, (int) $dept->id);
            if ($current) {
                $ids[] = (int) $current->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function restrictToCurrentJobs($query, User $user, string $column = 'id'): void
    {
        if (! $user->isFieldTech()) {
            return;
        }

        $ids = $this->currentIdsFor($user);
        $query->whereIn($column, $ids ?: [0]);
    }

    /**
     * @param  list<int|string>  $orderIds
     */
    private function notifyReordered(array $orderIds): void
    {
        $order = ServiceOrder::query()->find($orderIds[0] ?? null);
        if ($order) {
            StaffRealtime::order($order, 'updated');
        }
    }

    private function assertCurrentJob(ServiceOrder $order, User $tech): void
    {
        $current = $this->currentFor($tech, (int) $order->department_id);
        if (! $current || $current->id !== $order->id) {
            throw new InvalidArgumentException('This is not your current job.');
        }
    }

    private function assertCanHoldOrCancel(ServiceOrder $order): void
    {
        $order->loadMissing('invoices');
        if ($order->status === 'completed') {
            throw new InvalidArgumentException('Completed orders cannot be held or cancelled.');
        }
        if ($order->invoices->contains(fn ($invoice) => $invoice->status === 'confirmed')) {
            throw new InvalidArgumentException('Orders with a confirmed invoice cannot be held or cancelled.');
        }
    }

    private function voidDraftInvoice(ServiceOrder $order, InvoiceService $invoices): void
    {
        $order->loadMissing('invoices');
        foreach ($order->invoices->where('status', 'draft') as $invoice) {
            $invoices->voidDraft($invoice);
        }
    }
}
