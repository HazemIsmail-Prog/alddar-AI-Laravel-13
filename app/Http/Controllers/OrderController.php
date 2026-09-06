<?php

namespace App\Http\Controllers;

use App\Models\ClientLocation;
use App\Models\Department;
use App\Models\OrderStatusHistory;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use App\Support\RequestFilters;
use App\Support\StaffRealtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request, PaymentService $payments, OrderService $orders)
    {
        $user = $request->user();
        $q = ServiceOrder::query()->with([
            'client', 'phone', 'location', 'department', 'technician', 'contract', 'invoices', 'invoice', 'creator',
        ])->latest('id');

        if (! $user->canSeeAllOrders()) {
            $q->where('technician_id', $user->id);
        }
        $orders->restrictToCurrentJobs($q, $user);

        $this->applyIndexFilters($q, $request);

        $rows = $q->get();
        $payments->attachOrderNetDue($rows);

        return $rows->map(fn (ServiceOrder $order) => $orders->presentFor($user, $order))->values();
    }

    public function filterOptions(Request $request)
    {
        $user = $request->user();
        $orders = ServiceOrder::query();
        if (! $user->canSeeAllOrders()) {
            $orders->where('technician_id', $user->id);
        }

        $creatorIds = (clone $orders)->distinct()->pluck('created_by');
        $techIds = (clone $orders)->whereNotNull('technician_id')->distinct()->pluck('technician_id');

        return [
            'creators' => User::query()->whereIn('id', $creatorIds)->orderBy('name_en')->get()->map->toNamePayload()->values(),
            'technicians' => User::query()->whereIn('id', $techIds)->orderBy('name_en')->get()->map->toNamePayload()->values(),
        ];
    }

    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'phone_id' => ['required', Rule::exists('client_phones', 'id')->where('client_id', $request->integer('client_id'))],
            'location_id' => ['required', 'exists:client_locations,id'],
            'department_id' => ['required', Department::serviceIdRule()],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'notes' => ['nullable', 'string'],
            'planned_date' => ['nullable', 'date'],
        ]);

        $order = DB::transaction(function () use ($data, $request, $orders) {
            $order = ServiceOrder::query()->create([
                ...$data,
                'planned_date' => $data['planned_date'] ?? now()->toDateString(),
                'created_by' => $request->user()->id,
                'source' => 'manual',
                'status' => 'pending',
            ]);
            $order->statusHistory()->create([
                'user_id' => $request->user()->id,
                'from_status' => null,
                'to_status' => 'pending',
                'reason' => 'Order created',
                'created_by' => $request->user()->id,
            ]);
            $orders->prependToWaitingQueue($order);
            $order->refresh();

            return $order;
        });

        StaffRealtime::order($order, 'created');

        return $order->load(['client', 'phone', 'location', 'department', 'contract', 'statusHistory.user', 'statusHistory.technician']);
    }

    public function show(Request $request, ServiceOrder $order, PaymentService $payments, OrderService $orders)
    {
        $user = $request->user();
        if (! $user->canSeeAllOrders() && (int) $order->technician_id !== (int) $user->id) {
            abort(403);
        }
        if ($user->isFieldTech() && ! $orders->isCurrentJob($user, $order)) {
            abort(403);
        }
        if (! $orders->detailsVisibleTo($user, $order)) {
            return $orders->presentFor($user, $order);
        }

        $order->load([
            'client.phones', 'phone', 'location.machines', 'department', 'technician', 'contract.machines',
            'invoices.items.item', 'invoices.allocations', 'invoice', 'statusHistory.user', 'statusHistory.technician', 'creator',
        ]);
        $payments->attachOrderNetDue([$order]);
        $payments->hydrateClientWallet($order->client);

        return $order;
    }

    public function update(Request $request, ServiceOrder $order)
    {
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Completed or cancelled orders cannot be edited.'], 422);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'department_id' => ['sometimes', Department::serviceIdRule()],
            'location_id' => ['sometimes', 'exists:client_locations,id'],
            'phone_id' => ['sometimes', Rule::exists('client_phones', 'id')->where('client_id', $order->client_id)],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'planned_date' => ['sometimes', 'nullable', 'date'],
        ]);

        if (isset($data['location_id'])) {
            $location = ClientLocation::query()->findOrFail($data['location_id']);
            if ((int) $location->client_id !== (int) $order->client_id) {
                return response()->json(['message' => 'Location must belong to this client.'], 422);
            }
        }

        $order->update($data);
        StaffRealtime::order($order, 'updated');

        return $order->load(['client', 'phone', 'location', 'department', 'technician', 'contract']);
    }

    public function destroy(ServiceOrder $order)
    {
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be deleted. Hold or cancel assigned work instead.'], 422);
        }
        if ($order->invoices()->where('status', '!=', 'voided')->exists()) {
            return response()->json(['message' => 'This order has an invoice and cannot be deleted.'], 422);
        }
        $order->delete();

        return response()->json(['ok' => true]);
    }

    public function board(Request $request)
    {
        $user = $request->user();
        $accessible = $user->dispatchableServiceDepartments();
        $accessibleIds = $accessible->pluck('id');

        $departmentId = $request->integer('department_id') ?: null;
        if ($departmentId && ! $accessibleIds->contains($departmentId)) {
            abort(403);
        }

        if (! $departmentId && $request->integer('order')) {
            $fromOrder = ServiceOrder::query()->find($request->integer('order'));
            if ($fromOrder && $accessibleIds->contains((int) $fromOrder->department_id)) {
                $departmentId = (int) $fromOrder->department_id;
            }
        }

        if (! $departmentId) {
            $departmentId = $accessibleIds->first();
        }

        $departments = $this->departmentsWithTodayCounts($accessible);

        if (! $departmentId) {
            return [
                'departments' => $departments,
                'department_id' => null,
                'unassigned' => [],
                'planned' => [],
                'held' => [],
                'technicians' => [],
            ];
        }

        $orders = ServiceOrder::query()
            ->with(['client', 'phone', 'location', 'technician', 'invoices', 'invoice', 'department', 'statusHistory'])
            ->where('department_id', $departmentId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $technicians = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'technician'))
            ->whereHas('departments', fn ($q) => $q->where('departments.id', $departmentId))
            ->with(['warehouse', 'departments'])
            ->orderBy('name_en')
            ->get();

        $held = $orders->where('status', 'on_hold')->values()->map(function (ServiceOrder $order) {
            $order->setAttribute(
                'hold_reason',
                $order->statusHistory->where('to_status', 'on_hold')->last()?->reason
            );

            return $order;
        });
        $orders->each(fn (ServiceOrder $order) => $order->unsetRelation('statusHistory'));

        $waiting = $orders->whereNull('technician_id')->where('status', '!=', 'on_hold');
        $completedByTech = $this->technicianCompletedToday((int) $departmentId);
        $cancelledByTech = $this->technicianCancelledToday((int) $departmentId);

        return [
            'departments' => $departments,
            'department_id' => (int) $departmentId,
            'unassigned' => $waiting->reject(fn (ServiceOrder $order) => $order->isPlannedFuture())->values(),
            'planned' => $waiting->filter(fn (ServiceOrder $order) => $order->isPlannedFuture())->values(),
            'held' => $held,
            'technicians' => $technicians->map(fn (User $tech) => [
                'user' => $tech,
                'orders' => $orders->where('technician_id', $tech->id)->values(),
                'completed_today' => (int) ($completedByTech[$tech->id] ?? 0),
                'cancelled_today' => (int) ($cancelledByTech[$tech->id] ?? 0),
            ]),
        ];
    }

    public function assign(Request $request, ServiceOrder $order, OrderService $orders)
    {
        $this->assertCanDispatchOrder($request->user(), $order);
        $data = $request->validate([
            'technician_id' => ['required', 'exists:users,id'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);
        $tech = User::query()->with('departments')->findOrFail($data['technician_id']);

        return $this->domain(fn () => $orders->assign($order, $tech, $request->user(), $data['sort_order'] ?? null));
    }

    public function reorder(Request $request, User $technician, OrderService $orders)
    {
        $data = $request->validate(['order_ids' => ['required', 'array']]);
        $this->assertCanDispatchOrderIds($request->user(), $data['order_ids']);
        $orders->reorder($technician, $data['order_ids']);

        return ['ok' => true];
    }

    public function reorderQueue(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'queue' => ['required', 'in:unassigned,held,planned'],
            'order_ids' => ['required', 'array'],
            'order_ids.*' => ['integer', 'exists:service_orders,id'],
        ]);
        $this->assertCanDispatchOrderIds($request->user(), $data['order_ids']);
        $orders->reorderQueue($data['queue'], $data['order_ids']);

        return ['ok' => true];
    }

    public function hold(Request $request, ServiceOrder $order, OrderService $orders, InvoiceService $invoices)
    {
        $this->assertCanDispatchOrder($request->user(), $order);
        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->domain(fn () => $orders->hold($order, $request->user(), $data['reason'], $invoices));
    }

    public function cancel(Request $request, ServiceOrder $order, OrderService $orders, InvoiceService $invoices)
    {
        $this->assertCanDispatchOrder($request->user(), $order);
        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->domain(fn () => $orders->cancel($order, $request->user(), $data['reason'], $invoices));
    }

    public function current(Request $request, OrderService $orders, PaymentService $payments)
    {
        $user = $request->user();
        $accessible = $user->serviceDepartments();
        $accessibleIds = $accessible->pluck('id');

        $departmentId = $request->integer('department_id') ?: null;
        if ($departmentId && ! $accessibleIds->contains($departmentId)) {
            abort(403);
        }
        if (! $departmentId) {
            $departmentId = $this->defaultTechDepartmentId($user, $accessibleIds);
        }

        $departments = $accessible->map(fn (Department $dept) => $dept->toNamePayload())->values();
        $currents = $this->currentJobIdsByDepartment($accessible, $user, $orders);

        if (! $departmentId) {
            return [
                'departments' => $departments,
                'department_id' => null,
                'current' => null,
                'queue' => [],
                'currents' => $currents,
            ];
        }

        $order = $orders->currentFor($user, (int) $departmentId);
        $current = null;
        if ($order) {
            if ($orders->detailsVisibleTo($user, $order)) {
                $this->hydrateTechOrder($order, $payments);
            }
            $current = $orders->presentFor($user, $order);
        }

        $queue = [];
        if (! $user->isFieldTech()) {
            $queue = ServiceOrder::query()
                ->with(['client:id,name', 'location:id,label'])
                ->where('technician_id', $user->id)
                ->where('department_id', $departmentId)
                ->whereIn('status', ['assigned', 'accepted', 'reached'])
                ->when($order, fn ($q) => $q->where('id', '!=', $order->id))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ServiceOrder $row) => [
                    'id' => $row->id,
                    'status' => $row->status,
                    'client' => $row->client?->name,
                    'location' => $row->location?->label,
                    'notes' => $row->notes,
                ])
                ->values();
        }

        return [
            'departments' => $departments,
            'department_id' => (int) $departmentId,
            'current' => $current,
            'queue' => $queue,
            'currents' => $currents,
        ];
    }

    public function accept(Request $request, ServiceOrder $order, OrderService $orders)
    {
        return $this->domain(fn () => $orders->accept($order, $request->user()));
    }

    public function reached(Request $request, ServiceOrder $order, OrderService $orders)
    {
        return $this->domain(fn () => $orders->reached($order, $request->user()));
    }

    public function complete(Request $request, ServiceOrder $order, OrderService $orders)
    {
        return $this->domain(fn () => $orders->complete($order, $request->user()));
    }

    private function applyIndexFilters($q, Request $request): void
    {
        $statuses = RequestFilters::listParam($request, 'status');
        if ($statuses) {
            $q->whereIn('status', $statuses);
        }
        $departments = RequestFilters::intList($request, 'department_id');
        if ($departments) {
            $q->whereIn('department_id', $departments);
        }

        $number = trim($request->string('number')->toString());
        if ($number !== '') {
            $id = (int) ltrim($number, '#');
            $q->where('id', $id > 0 ? $id : 0);
        }

        $creators = RequestFilters::intList($request, 'created_by');
        if ($creators) {
            $q->whereIn('created_by', $creators);
        }

        $technicians = RequestFilters::intList($request, 'technician_id');
        if ($technicians) {
            $q->where(function ($query) use ($technicians) {
                $query->whereIn('technician_id', $technicians)
                    ->orWhere(function ($cancelled) use ($technicians) {
                        $cancelled->where('status', 'cancelled')
                            ->whereHas('statusHistory', function ($history) use ($technicians) {
                                $history->where('to_status', 'assigned')
                                    ->whereIn('technician_id', $technicians);
                            });
                    });
            });
        }

        RequestFilters::applyDateRange($q, 'created_at', $request->input('created_from'), $request->input('created_to'));
        RequestFilters::applyDateRange($q, 'planned_date', $request->input('due_from'), $request->input('due_to'));
        RequestFilters::applyDateRange($q, 'completed_at', $request->input('completed_from'), $request->input('completed_to'));

        $cancelledFrom = RequestFilters::dateOrNull($request->input('cancelled_from'));
        $cancelledTo = RequestFilters::dateOrNull($request->input('cancelled_to'));
        if ($cancelledFrom || $cancelledTo) {
            $q->whereHas('statusHistory', function ($history) use ($cancelledFrom, $cancelledTo) {
                $history->where('to_status', 'cancelled');
                RequestFilters::applyDateRange($history, 'created_at', $cancelledFrom, $cancelledTo);
            });
        }

        $clientName = trim($request->string('client_name')->toString());
        if ($clientName !== '') {
            $like = '%'.$clientName.'%';
            $q->whereHas('client', fn ($client) => $client->where('name', 'like', $like));
        }

        $clientPhone = trim($request->string('client_phone')->toString());
        if ($clientPhone !== '') {
            $digits = preg_replace('/\D+/', '', $clientPhone) ?: $clientPhone;
            $like = '%'.$clientPhone.'%';
            $digitLike = '%'.$digits.'%';
            $q->where(function ($query) use ($like, $digitLike) {
                $matchPhone = function ($phone) use ($like, $digitLike) {
                    $phone->where('phone', 'like', $like)
                        ->orWhereRaw("replace(replace(replace(phone, '-', ''), ' ', ''), '+', '') like ?", [$digitLike]);
                };
                $query->whereHas('phone', $matchPhone)
                    ->orWhereHas('client.phones', $matchPhone);
            });
        }

        if ($request->exists('has_invoice') && $request->input('has_invoice') !== '' && $request->input('has_invoice') !== null) {
            $hasInvoice = RequestFilters::truthy($request->input('has_invoice'));
            $openInvoice = fn ($invoice) => $invoice->where('status', '!=', 'voided');
            if ($hasInvoice) {
                $q->whereHas('invoices', $openInvoice);
            } else {
                $q->whereDoesntHave('invoices', $openInvoice);
            }
        }

        $paymentStatuses = array_values(array_intersect(RequestFilters::listParam($request, 'payment_status'), ['unpaid', 'partial', 'paid']));
        if ($paymentStatuses) {
            $this->applyPaymentStatus($q, $paymentStatuses);
        }

        if ($search = trim($request->string('search')->toString())) {
            $like = '%'.$search.'%';
            $id = (int) ltrim($search, '#');
            $q->where(function ($query) use ($like, $id, $search) {
                $query->where('notes', 'like', $like)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('technician', fn ($t) => $t->where(function ($n) use ($like) {
                        $n->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like);
                    }))
                    ->orWhereHas('department', fn ($d) => $d->where(function ($n) use ($like) {
                        $n->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like);
                    }));
                if ($id > 0 && (string) $id === ltrim($search, '#')) {
                    $query->orWhere('id', $id);
                }
            });
        }
    }

    private function applyPaymentStatus($q, array $statuses): void
    {
        $paidSql = 'coalesce((select sum(amount) from payment_allocations where payment_allocations.invoice_id = invoices.id), 0)';
        $q->where(function ($outer) use ($statuses, $paidSql) {
            foreach ($statuses as $status) {
                $outer->orWhereHas('invoices', function ($invoice) use ($status, $paidSql) {
                    $invoice->where('status', 'confirmed');
                    match ($status) {
                        'unpaid' => $invoice->whereRaw($paidSql.' = 0'),
                        'partial' => $invoice->whereRaw($paidSql.' > 0 and '.$paidSql.' < invoices.total'),
                        'paid' => $invoice->whereRaw($paidSql.' >= invoices.total'),
                        default => $invoice->whereRaw('0 = 1'),
                    };
                });
            }
        });
    }

    private function departmentsWithTodayCounts($accessible)
    {
        $ids = $accessible->pluck('id');
        $completed = collect();
        $cancelled = collect();
        if ($ids->isNotEmpty()) {
            $today = today();
            $completed = ServiceOrder::query()
                ->whereIn('department_id', $ids)
                ->whereDate('completed_at', $today)
                ->selectRaw('department_id, count(*) as aggregate')
                ->groupBy('department_id')
                ->pluck('aggregate', 'department_id');
            $cancelled = ServiceOrder::query()
                ->whereIn('department_id', $ids)
                ->whereHas(
                    'statusHistory',
                    fn ($q) => $q->where('to_status', 'cancelled')->whereDate('created_at', $today)
                )
                ->selectRaw('department_id, count(*) as aggregate')
                ->groupBy('department_id')
                ->pluck('aggregate', 'department_id');
        }

        return $accessible->map(fn (Department $dept) => [
            ...$dept->toNamePayload(),
            'completed_today' => (int) ($completed[$dept->id] ?? 0),
            'cancelled_today' => (int) ($cancelled[$dept->id] ?? 0),
        ])->values();
    }

    private function technicianCompletedToday(int $departmentId)
    {
        return ServiceOrder::query()
            ->where('department_id', $departmentId)
            ->whereDate('completed_at', today())
            ->whereNotNull('technician_id')
            ->selectRaw('technician_id, count(*) as aggregate')
            ->groupBy('technician_id')
            ->pluck('aggregate', 'technician_id');
    }

    private function technicianCancelledToday(int $departmentId)
    {
        $ids = ServiceOrder::query()
            ->where('department_id', $departmentId)
            ->whereHas(
                'statusHistory',
                fn ($q) => $q->where('to_status', 'cancelled')->whereDate('created_at', today())
            )
            ->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $latestAssigned = OrderStatusHistory::query()
            ->whereIn('order_id', $ids)
            ->where('to_status', 'assigned')
            ->whereNotNull('technician_id')
            ->selectRaw('max(id) as latest_id')
            ->groupBy('order_id')
            ->pluck('latest_id');

        if ($latestAssigned->isEmpty()) {
            return collect();
        }

        return OrderStatusHistory::query()
            ->whereIn('id', $latestAssigned)
            ->selectRaw('technician_id, count(*) as aggregate')
            ->groupBy('technician_id')
            ->pluck('aggregate', 'technician_id');
    }

    private function assertCanDispatchOrder(User $user, ServiceOrder $order): void
    {
        if (! $user->canDispatchDepartment((int) $order->department_id)) {
            abort(403);
        }
    }

    private function assertCanDispatchOrderIds(User $user, array $orderIds): void
    {
        $ids = $user->dispatchableServiceDepartments()->pluck('id');
        $orders = ServiceOrder::query()->whereIn('id', $orderIds)->get(['id', 'department_id']);
        foreach ($orders as $order) {
            if (! $ids->contains((int) $order->department_id)) {
                abort(403);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Department>  $departments
     * @return array<int, int>
     */
    private function currentJobIdsByDepartment($departments, User $user, OrderService $orders): array
    {
        $currents = [];
        foreach ($departments as $dept) {
            $current = $orders->currentFor($user, (int) $dept->id);
            if ($current) {
                $currents[(int) $dept->id] = (int) $current->id;
            }
        }

        return $currents;
    }

    private function hydrateTechOrder(ServiceOrder $order, PaymentService $payments): void
    {
        $payments->hydrateClientWallet($order->client);
        $order->loadMissing('invoices');
        foreach ($order->invoices as $invoice) {
            $paid = $payments->allocatedToInvoice($invoice->id);
            $invoice->setAttribute('paid', $paid);
            $invoice->setAttribute(
                'remaining',
                $invoice->status === 'confirmed'
                    ? max(0, (int) $invoice->total - $paid)
                    : 0,
            );
        }
        if ($order->invoice) {
            $match = $order->invoices->firstWhere('id', $order->invoice->id);
            if ($match) {
                $order->invoice->setAttribute('paid', $match->paid);
                $order->invoice->setAttribute('remaining', $match->remaining);
            }
        }
    }

    private function defaultTechDepartmentId(User $user, $accessibleIds): ?int
    {
        if ($accessibleIds->isEmpty()) {
            return null;
        }

        $active = ServiceOrder::query()
            ->where('technician_id', $user->id)
            ->whereIn('department_id', $accessibleIds)
            ->whereIn('status', ['accepted', 'reached'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('department_id');
        if ($active) {
            return (int) $active;
        }

        $assigned = ServiceOrder::query()
            ->where('technician_id', $user->id)
            ->whereIn('department_id', $accessibleIds)
            ->where('status', 'assigned')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('department_id');

        return $assigned ? (int) $assigned : (int) $accessibleIds->first();
    }
}
