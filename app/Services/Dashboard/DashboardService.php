<?php

namespace App\Services\Dashboard;

use App\Models\AcMachine;
use App\Models\Client;
use App\Models\ClientLocation;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ServiceOrder;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\LedgerService;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderService;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    private const OPEN_ORDER_STATUSES = ['pending', 'on_hold', 'assigned', 'accepted', 'reached'];

    private const ORDER_STATUSES = [
        'pending', 'on_hold', 'assigned', 'accepted', 'reached', 'completed', 'cancelled',
    ];

    public function __construct(
        private OrderService $orders,
        private InventoryService $inventory,
        private LedgerService $ledger,
    ) {}

    public function for(User $user): array
    {
        $user->loadMissing(['roles', 'departments', 'warehouse']);

        $payload = [
            'orders' => $this->widget($user, 'dashboard.orders', $user->hasPermission('orders.view'))
                ? $this->orders($user) : null,
            'dispatch' => $this->widget($user, 'dashboard.dispatch', $user->hasPermission('orders.dispatch'))
                ? $this->dispatch($user) : null,
            'tech' => $this->widget($user, 'dashboard.tech', $this->canSeeTech($user))
                ? $this->tech($user) : null,
            'clients' => $this->widget($user, 'dashboard.clients', $user->hasPermission('clients.view'))
                ? $this->clients() : null,
            'contracts' => $this->widget($user, 'dashboard.contracts', $user->hasPermission('contracts.view'))
                ? $this->contracts() : null,
            'invoices' => $this->widget($user, 'dashboard.invoices', $user->hasPermission('invoices.view'))
                ? $this->invoices($user) : null,
            'inventory' => $this->widget($user, 'dashboard.inventory', $this->canSeeInventory($user))
                ? $this->inventory($user) : null,
            'accounting' => $this->widget($user, 'dashboard.accounting', $user->hasPermission('accounting.view'))
                ? $this->accounting() : null,
            'staff' => $this->widget($user, 'dashboard.staff', $user->hasPermission('users.view'))
                ? $this->staff() : null,
            'activity' => $this->widget(
                $user,
                'dashboard.activity',
                $user->hasPermission('orders.view')
                    || $user->hasPermission('invoices.view')
                    || $user->hasPermission('payments.collect'),
            ) ? $this->activity($user) : null,
        ];

        $payload['alerts'] = $this->alerts($user, $payload);

        return $payload;
    }

    private function widget(User $user, string $slug, bool $canSeeData): bool
    {
        return $canSeeData && $user->hasPermission($slug);
    }

    private function canSeeTech(User $user): bool
    {
        return $user->hasPermission('orders.accept')
            || $user->hasPermission('orders.reached')
            || $user->hasPermission('orders.complete')
            || $user->hasPermission('invoices.create');
    }

    private function canSeeInventory(User $user): bool
    {
        return $user->hasPermission('inventory.view')
            || $user->hasPermission('inventory.view_own')
            || $user->hasPermission('items.view')
            || $user->hasPermission('transfers.view')
            || $user->hasPermission('adjustments.view');
    }

    private function orderQuery(User $user): Builder
    {
        $q = ServiceOrder::query();
        if (! $user->canSeeAllOrders()) {
            $q->where('technician_id', $user->id);
        }

        return $q;
    }

    private function invoiceQuery(User $user): Builder
    {
        $q = Invoice::query();
        if (! $user->canSeeAllInvoices()) {
            $q->whereHas('order', fn ($order) => $order->where('technician_id', $user->id));
        }

        return $q;
    }

    private function dispatchQuery(User $user): Builder
    {
        $deptIds = $user->dispatchableServiceDepartments()->pluck('id');

        return ServiceOrder::query()->whereIn('department_id', $deptIds);
    }

    private function orders(User $user): array
    {
        $base = $this->orderQuery($user);
        $counts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = collect(self::ORDER_STATUSES)->map(fn (string $status) => [
            'status' => $status,
            'count' => (int) ($counts[$status] ?? 0),
        ])->values()->all();

        $recent = (clone $base)
            ->with(['client:id,name', 'technician:id,name_en,name_ar', 'location:id,label'])
            ->latest('id')
            ->limit(6)
            ->get(['id', 'client_id', 'technician_id', 'location_id', 'status', 'created_at', 'planned_date'])
            ->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'status' => $order->status,
                'client' => $order->client?->name,
                'client_id' => $order->client_id,
                'technician' => $order->technician?->toNamePayload(),
                'location' => $order->location?->label,
                'created_at' => $order->created_at?->toIso8601String(),
            ])->all();

        return [
            'total' => (int) $counts->sum(),
            'open' => (int) collect(self::OPEN_ORDER_STATUSES)->sum(fn ($s) => (int) ($counts[$s] ?? 0)),
            'completed_today' => (clone $base)->whereDate('completed_at', today())->count(),
            'by_status' => $byStatus,
            'recent' => $recent,
        ];
    }

    private function dispatch(User $user): array
    {
        $base = $this->dispatchQuery($user);
        $jobCounts = ServiceOrder::query()
            ->whereIn('status', ['assigned', 'accepted', 'reached'])
            ->whereNotNull('technician_id')
            ->selectRaw('technician_id, count(*) as aggregate')
            ->groupBy('technician_id')
            ->pluck('aggregate', 'technician_id');

        $technicians = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'technician'))
            ->where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar'])
            ->map(fn (User $tech) => [
                ...$tech->toNamePayload(),
                'active_jobs' => (int) ($jobCounts[$tech->id] ?? 0),
            ])->all();

        return [
            'unassigned' => (clone $base)->where('status', 'pending')->whereNull('technician_id')->count(),
            'held' => (clone $base)->where('status', 'on_hold')->count(),
            'in_field' => (clone $base)->whereIn('status', ['assigned', 'accepted', 'reached'])->count(),
            'technicians' => $technicians,
        ];
    }

    private function tech(User $user): array
    {
        $departments = $user->serviceDepartments()->map(function ($dept) use ($user) {
            $current = $this->orders->currentFor($user, $dept->id);
            $queue = ServiceOrder::query()
                ->where('technician_id', $user->id)
                ->where('department_id', $dept->id)
                ->whereIn('status', ['assigned', 'accepted', 'reached'])
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->count();

            return [
                'id' => $dept->id,
                'name_en' => $dept->name_en,
                'name_ar' => $dept->name_ar,
                'current' => $current ? $this->techCurrentPayload($current, $user) : null,
                'queue' => $queue,
            ];
        })->values();

        $active = $departments->first(fn ($dept) => in_array($dept['current']['status'] ?? '', ['accepted', 'reached'], true))
            ?? $departments->first(fn ($dept) => $dept['current']);

        return [
            'current' => $active['current'] ?? null,
            'queue' => $departments->sum('queue'),
            'departments' => $departments->all(),
        ];
    }

    private function techCurrentPayload(ServiceOrder $current, User $user): array
    {
        if (! $this->orders->detailsVisibleTo($user, $current)) {
            return [
                'id' => $current->id,
                'status' => $current->status,
                'department_id' => $current->department_id,
            ];
        }

        return [
            'id' => $current->id,
            'status' => $current->status,
            'client' => $current->client?->name,
            'client_id' => $current->client_id,
            'location' => $current->location?->label,
            'notes' => $current->notes,
            'department_id' => $current->department_id,
            'department' => $current->department?->toNamePayload(),
        ];
    }

    private function clients(): array
    {
        return [
            'total' => Client::query()->count(),
            'new_month' => Client::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            'locations' => ClientLocation::query()->count(),
            'machines' => AcMachine::query()->count(),
        ];
    }

    private function contracts(): array
    {
        $end = now()->addDays(30)->toDateString();
        $overdue = ContractInstallment::query()
            ->whereIn('status', ['pending', 'billed'])
            ->whereDate('due_date', '<', today())
            ->count();

        $installmentDue = ContractInstallment::query()
            ->whereIn('status', ['pending', 'billed'])
            ->sum('amount');
        $installmentPaid = PaymentAllocation::query()->whereNotNull('installment_id')->sum('amount');

        return [
            'total' => Contract::query()->count(),
            'active' => Contract::query()->where('status', 'active')->count(),
            'expiring_30' => Contract::query()
                ->where('status', 'active')
                ->whereBetween('end_date', [today()->toDateString(), $end])
                ->count(),
            'value' => (int) Contract::query()->where('status', 'active')->sum('total_amount'),
            'overdue_installments' => $overdue,
            'installment_outstanding' => max(0, (int) $installmentDue - (int) $installmentPaid),
        ];
    }

    private function invoices(User $user): array
    {
        $base = $this->invoiceQuery($user);
        $confirmed = (clone $base)->where('status', 'confirmed');
        $billed = (int) (clone $confirmed)->sum('total');
        $confirmedIds = (clone $confirmed)->pluck('id');
        $paid = $confirmedIds->isEmpty()
            ? 0
            : (int) PaymentAllocation::query()->whereIn('invoice_id', $confirmedIds)->sum('amount');

        $recent = (clone $base)
            ->with(['order.client:id,name'])
            ->latest('id')
            ->limit(5)
            ->get(['id', 'order_id', 'status', 'total', 'created_at'])
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'status' => $invoice->status,
                'total' => (int) $invoice->total,
                'client' => $invoice->order?->client?->name,
            ])->all();

        $monthStart = now()->startOfMonth();
        $collectedMonth = (int) Payment::query()
            ->when(
                ! $user->canSeeAllInvoices(),
                fn ($q) => $q->where('received_by', $user->id),
            )
            ->where('created_at', '>=', $monthStart)
            ->sum('amount');

        return [
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'confirmed' => (clone $confirmed)->count(),
            'outstanding' => max(0, $billed - $paid),
            'collected_month' => $collectedMonth,
            'recent' => $recent,
        ];
    }

    private function inventory(User $user): array
    {
        $ownOnly = ! $user->canSeeAllStock() && $user->hasPermission('inventory.view_own');
        $warehouseId = $ownOnly ? $user->warehouse?->id : null;

        $levels = StockLevel::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));

        $trackedIds = Item::query()->where('type', 'tracked_part')->pluck('id');
        $withStock = (clone $levels)
            ->whereIn('item_id', $trackedIds)
            ->where('qty', '>', 0)
            ->distinct()
            ->pluck('item_id');

        $payload = [
            'warehouses' => $ownOnly ? ($warehouseId ? 1 : 0) : Warehouse::query()->count(),
            'skus_on_hand' => (clone $levels)->where('qty', '>', 0)->pluck('item_id')->unique()->count(),
            'out_of_stock' => $trackedIds->diff($withStock)->count(),
            'in_transit' => $user->hasPermission('transfers.view') || $user->hasPermission('inventory.view')
                ? WarehouseTransfer::query()->where('status', 'in_transit')->count()
                : 0,
            'draft_transfers' => $user->hasPermission('transfers.view')
                ? WarehouseTransfer::query()->where('status', 'draft')->count()
                : 0,
            'draft_adjustments' => $user->hasPermission('adjustments.view')
                ? StockAdjustment::query()->where('status', 'draft')->count()
                : 0,
            'on_hand_value' => null,
            'own_only' => $ownOnly,
        ];

        if ($user->hasPermission('inventory.view') || $user->hasPermission('inventory.valuation')) {
            $payload['on_hand_value'] = $this->inventory->onHandTotal();
        } elseif ($ownOnly && $warehouseId) {
            $payload['on_hand_value'] = (int) StockLevel::query()
                ->where('warehouse_id', $warehouseId)
                ->get()
                ->sum(fn (StockLevel $level) => $this->inventory->moneyFromQty($level->qty, $level->average_cost));
        }

        return $payload;
    }

    private function accounting(): array
    {
        $tb = collect($this->ledger->trialBalance())->keyBy('code');
        $pick = fn (string $code) => (int) ($tb[$code]['balance'] ?? 0);

        return [
            'cash' => $pick('1000') + $pick('1010'),
            'ar' => $pick('1100'),
            'inventory' => $pick('1200'),
            'in_transit' => $pick('1210'),
            'unearned' => $pick('2100'),
            'client_credit' => $pick('2110'),
            'revenue' => $pick('4000') + $pick('4010') + $pick('4020'),
            'cogs' => $pick('5000') + $pick('5010'),
        ];
    }

    private function staff(): array
    {
        return [
            'active' => User::query()->where('is_active', true)->count(),
            'inactive' => User::query()->where('is_active', false)->count(),
            'departments' => Department::query()->count(),
        ];
    }

    /**
     * @return list<array{date: string, orders: int, completed: int, collected: int}>|null
     */
    private function activity(User $user): ?array
    {
        $canOrders = $user->hasPermission('orders.view');
        $canMoney = $user->hasPermission('invoices.view') || $user->hasPermission('payments.collect');
        if (! $canOrders && ! $canMoney) {
            return null;
        }

        $start = now()->subDays(13)->startOfDay();
        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $days[$date] = ['date' => $date, 'orders' => 0, 'completed' => 0, 'collected' => 0];
        }

        if ($canOrders) {
            foreach ($this->orderQuery($user)->where('created_at', '>=', $start)->get(['created_at']) as $order) {
                $day = $order->created_at?->toDateString();
                if ($day && isset($days[$day])) {
                    $days[$day]['orders']++;
                }
            }
            foreach ($this->orderQuery($user)->where('completed_at', '>=', $start)->get(['completed_at']) as $order) {
                $day = $order->completed_at?->toDateString();
                if ($day && isset($days[$day])) {
                    $days[$day]['completed']++;
                }
            }
        }

        if ($canMoney) {
            $payments = Payment::query()
                ->when(
                    ! $user->canSeeAllInvoices(),
                    fn ($q) => $q->where('received_by', $user->id),
                )
                ->where('created_at', '>=', $start)
                ->get(['created_at', 'amount']);
            foreach ($payments as $payment) {
                $day = $payment->created_at?->toDateString();
                if ($day && isset($days[$day])) {
                    $days[$day]['collected'] += (int) $payment->amount;
                }
            }
        }

        return array_values($days);
    }

    private function alerts(User $user, array $payload): array
    {
        $alerts = [];
        $push = function (string $key, int $count, string $to) use (&$alerts) {
            if ($count > 0) {
                $alerts[] = ['key' => $key, 'count' => $count, 'to' => $to];
            }
        };

        if ($payload['dispatch']) {
            $push('unassigned', (int) $payload['dispatch']['unassigned'], '/dispatch');
            $push('held', (int) $payload['dispatch']['held'], '/dispatch');
        }
        if ($payload['contracts']) {
            $push('overdueInstallments', (int) $payload['contracts']['overdue_installments'], '/contracts');
            $push('expiringContracts', (int) $payload['contracts']['expiring_30'], '/contracts');
        }
        if ($payload['invoices']) {
            $push('draftInvoices', (int) $payload['invoices']['draft'], '/invoices');
        }
        if ($payload['inventory'] && ($user->hasPermission('transfers.view') || $user->hasPermission('inventory.view'))) {
            $push('inTransit', (int) $payload['inventory']['in_transit'], '/inventory');
        }

        return $alerts;
    }
}
