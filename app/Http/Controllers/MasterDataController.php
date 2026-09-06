<?php

namespace App\Http\Controllers;

use App\Models\AcMachine;
use App\Models\Client;
use App\Models\ClientLocation;
use App\Models\ClientPhone;
use App\Models\Department;
use App\Models\InvoiceItem;
use App\Services\Payments\PaymentService;
use App\Support\RequestFilters;
use App\Support\StaffRealtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MasterDataController extends Controller
{
    public function departments(Request $request)
    {
        $q = Department::query()
            ->with('users')
            ->withCount(['users', 'orders', 'contracts'])
            ->orderBy('name_en');

        if ($request->has('is_service')) {
            $q->where('is_service', $request->boolean('is_service'));
        }

        return $q->get();
    }

    public function storeDepartment(Request $request)
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'unique:departments,name_en'],
            'name_ar' => ['required', 'string', 'unique:departments,name_ar'],
            'is_service' => ['boolean'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $department = Department::query()->create([
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'is_service' => $data['is_service'] ?? true,
        ]);
        $department->users()->sync($data['user_ids'] ?? []);

        return response()->json($department->load('users')->loadCount(['users', 'orders', 'contracts']), 201);
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $data = $request->validate([
            'name_en' => ['sometimes', 'string', 'unique:departments,name_en,'.$department->id],
            'name_ar' => ['sometimes', 'string', 'unique:departments,name_ar,'.$department->id],
            'is_service' => ['sometimes', 'boolean'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        if (array_key_exists('is_service', $data) && ! $data['is_service'] && $department->is_service) {
            if ($department->orders()->exists() || $department->contracts()->exists()) {
                return response()->json(['message' => 'Service departments with orders or contracts cannot be changed to non-service.'], 422);
            }
        }
        $department->fill(collect($data)->only(['name_en', 'name_ar', 'is_service'])->all());
        $department->save();
        if (isset($data['user_ids'])) {
            $department->users()->sync($data['user_ids']);
        }

        return $department->load('users')->loadCount(['users', 'orders', 'contracts']);
    }

    public function destroyDepartment(Department $department)
    {
        if ($department->orders()->exists() || $department->contracts()->exists()) {
            return response()->json(['message' => 'This department still has orders or contracts.'], 422);
        }

        $department->users()->detach();
        $department->delete();

        return response()->noContent();
    }

    public function clients(Request $request, PaymentService $payments)
    {
        $search = trim($request->string('search')->toString());
        $compact = $request->boolean('compact');
        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        $q = Client::query()
            ->with($compact ? ['phones', 'locations', 'creator'] : ['phones', 'locations.machines', 'creator'])
            ->latest('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhereHas('phones', fn ($p) => $this->matchPhone($p, $like))
                    ->orWhereHas('locations', fn ($l) => $this->matchLocation($l, $like));
            });
        }

        $name = trim($request->string('name')->toString());
        if ($name !== '') {
            $q->where('name', 'like', '%'.$name.'%');
        }

        $phone = trim($request->string('phone')->toString());
        if ($phone !== '') {
            $q->whereHas('phones', fn ($p) => $this->matchPhone($p, '%'.$phone.'%'));
        }

        $location = trim($request->string('location')->toString());
        if ($location !== '') {
            $q->whereHas('locations', fn ($l) => $this->matchLocation($l, '%'.$location.'%'));
        }

        $createdFrom = RequestFilters::dateOrNull($request->input('created_from'));
        $createdTo = RequestFilters::dateOrNull($request->input('created_to'));
        if ($createdFrom) {
            $q->whereDate('created_at', '>=', $createdFrom);
        }
        if ($createdTo) {
            $q->whereDate('created_at', '<=', $createdTo);
        }

        $page = $q->paginate($perPage);
        if (! $compact) {
            $balances = $payments->balancesFor($page->getCollection()->pluck('id')->all());
            foreach ($page->getCollection() as $client) {
                $balance = $balances[$client->id] ?? ['wallet' => 0, 'outstanding' => 0, 'net_due' => 0];
                $client->setAttribute('wallet', $balance['wallet']);
                $client->setAttribute('outstanding', $balance['outstanding']);
                $client->setAttribute('net_due', $balance['net_due']);
            }
        }

        return $page;
    }

    public function storeClient(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'phones' => ['nullable', 'array'],
            'phones.*.country_code' => ['nullable', 'string'],
            'phones.*.phone' => ['nullable', 'string'],
            'phones.*.is_primary' => ['boolean'],
            'locations' => ['nullable', 'array'],
            'locations.*.label' => ['nullable', 'string'],
            'locations.*.country' => ['nullable', 'string'],
            'locations.*.city' => ['nullable', 'string'],
            'locations.*.area' => ['nullable', 'string'],
            'locations.*.block' => ['nullable', 'string'],
            'locations.*.street' => ['nullable', 'string'],
            'locations.*.avenue' => ['nullable', 'string'],
            'locations.*.building' => ['nullable', 'string'],
            'locations.*.floor' => ['nullable', 'string'],
            'locations.*.flat' => ['nullable', 'string'],
            'locations.*.extras' => ['nullable', 'string'],
            'locations.*.paci_number' => ['nullable', 'integer'],
            'locations.*.google_maps_link' => ['nullable', 'string'],
            'locations.*.machines' => ['nullable', 'array'],
            'locations.*.machines.*.brand' => ['nullable', 'string'],
            'locations.*.machines.*.model' => ['nullable', 'string'],
            'locations.*.machines.*.serial' => ['nullable', 'string'],
            'locations.*.machines.*.notes' => ['nullable', 'string'],
        ]);

        $phones = $this->filledPhones($data['phones'] ?? []);
        $locations = [];
        foreach ($data['locations'] ?? [] as $loc) {
            if ($this->isBlankLocation($loc)) {
                continue;
            }
            $locations[] = Validator::make($loc, [
                ...$this->locationRules(),
                'machines' => ['nullable', 'array'],
                'machines.*.brand' => ['nullable', 'string'],
                'machines.*.model' => ['nullable', 'string'],
                'machines.*.serial' => ['nullable', 'string'],
                'machines.*.notes' => ['nullable', 'string'],
            ])->validate();
        }

        $client = Client::query()->create(['name' => $data['name'], 'notes' => $data['notes'] ?? null]);
        foreach ($phones as $phone) {
            $client->phones()->create($phone);
        }
        foreach ($locations as $loc) {
            $machines = $loc['machines'] ?? [];
            unset($loc['machines'], $loc['address'], $loc['id']);
            $location = $client->locations()->create($loc);
            foreach ($machines as $machine) {
                $location->machines()->create($machine);
            }
        }

        StaffRealtime::client($client, 'created');

        return $client->load(['phones', 'locations.machines']);
    }

    public function updateClient(Request $request, Client $client)
    {
        $data = $request->validate(['name' => ['sometimes', 'string'], 'notes' => ['nullable', 'string']]);
        $client->update($data);
        StaffRealtime::client($client, 'updated');

        return $client->load(['phones', 'locations.machines']);
    }

    public function showClient(Client $client)
    {
        return $client->load(['phones', 'locations.machines', 'contracts.installments', 'orders', 'creator']);
    }

    public function clientSummary(Client $client, PaymentService $payments)
    {
        $openStatuses = ['pending', 'on_hold', 'assigned', 'accepted', 'reached'];

        $client->load([
            'creator',
            'phones',
            'locations.machines',
            'contracts' => fn ($q) => $q->latest('id'),
            'contracts.location',
            'contracts.department',
            'contracts.installments.allocations',
            'orders' => fn ($q) => $q->latest('id'),
            'orders.location',
            'orders.department',
            'orders.technician',
            'orders.invoices.allocations',
            'orders.invoice.allocations',
            'orders.contract',
            'payments' => fn ($q) => $q->latest('id'),
            'payments.receiver',
            'payments.creator',
            'payments.allocations.invoice',
            'payments.allocations.installment',
            'allocations' => fn ($q) => $q->whereNull('payment_id')->latest('id'),
            'allocations.applier',
            'allocations.creator',
            'allocations.invoice',
            'allocations.installment',
        ]);

        $invoices = $client->orders
            ->flatMap(fn ($order) => $order->invoices->isNotEmpty() ? $order->invoices : collect([$order->invoice]))
            ->filter()
            ->unique('id')
            ->values();

        $installments = $client->contracts->flatMap(function ($contract) {
            return $contract->installments->map(function ($row) use ($contract) {
                $paid = (int) $row->allocations->sum('amount');

                return [
                    'id' => $row->id,
                    'contract_id' => $contract->id,
                    'due_date' => $row->due_date,
                    'description' => $row->description,
                    'amount' => (int) $row->amount,
                    'paid' => $paid,
                    'remaining' => max(0, (int) $row->amount - $paid),
                    'status' => $row->status,
                ];
            });
        })->sortBy('due_date')->values();

        $invoiceRows = $invoices->map(function ($invoice) {
            $paid = (int) $invoice->allocations->sum('amount');
            $remaining = $invoice->status === 'confirmed'
                ? max(0, (int) $invoice->total - $paid)
                : 0;

            return [
                'id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'status' => $invoice->status,
                'total' => (int) $invoice->total,
                'paid' => $paid,
                'remaining' => $remaining,
                'created_at' => $invoice->created_at,
            ];
        })->sortByDesc('id')->values();

        $history = $client->payments->map(function ($payment) {
            $applied = $payment->allocations->map(fn ($row) => [
                'id' => $row->id,
                'amount' => (int) $row->amount,
                'invoice_id' => $row->invoice_id,
                'installment_id' => $row->installment_id,
                'contract_id' => $row->installment?->contract_id,
            ])->values();

            return [
                'id' => 'pay-'.$payment->id,
                'kind' => 'payment',
                'payment_id' => $payment->id,
                'amount' => (int) $payment->amount,
                'method' => $payment->method,
                'notes' => $payment->notes,
                'receiver' => $payment->receiver,
                'creator' => $payment->creator,
                'created_at' => $payment->created_at,
                'allocations' => $applied,
            ];
        })->concat($client->allocations->map(function ($row) {
            return [
                'id' => 'alloc-'.$row->id,
                'kind' => 'credit',
                'payment_id' => null,
                'amount' => (int) $row->amount,
                'method' => 'credit',
                'notes' => null,
                'receiver' => $row->applier,
                'creator' => $row->creator,
                'created_at' => $row->created_at,
                'allocations' => [[
                    'id' => $row->id,
                    'amount' => (int) $row->amount,
                    'invoice_id' => $row->invoice_id,
                    'installment_id' => $row->installment_id,
                    'contract_id' => $row->installment?->contract_id,
                ]],
            ];
        }))->sortByDesc('created_at')->values();

        $wallet = $payments->walletBalance($client->id);
        $outstanding = (int) $installments->sum('remaining') + (int) $invoiceRows->sum('remaining');
        $asOf = now()->toDateString();
        $currentDue = (int) $invoiceRows->sum('remaining') + (int) $installments
            ->filter(function ($row) use ($asOf) {
                $due = $row['due_date'] ?? '';
                $date = $due instanceof \DateTimeInterface ? $due->format('Y-m-d') : substr((string) $due, 0, 10);

                return $date !== '' && $date <= $asOf;
            })
            ->sum('remaining');

        return [
            'id' => $client->id,
            'name' => $client->name,
            'notes' => $client->notes,
            'created_at' => $client->created_at,
            'creator' => $client->creator,
            'phones' => $client->phones,
            'locations' => $client->locations,
            'totals' => [
                'locations' => $client->locations->count(),
                'machines' => $client->locations->sum(fn ($location) => $location->machines->count()),
                'contracts' => $client->contracts->count(),
                'active_contracts' => $client->contracts->where('status', 'active')->count(),
                'orders' => $client->orders->count(),
                'open_orders' => $client->orders->whereIn('status', $openStatuses)->count(),
                'invoices' => $invoiceRows->count(),
                'contract_value' => (int) $client->contracts->sum('total_amount'),
                'invoiced' => (int) $invoiceRows->where('status', 'confirmed')->sum('total'),
                'collected' => (int) $client->payments->sum('amount'),
                'wallet' => $wallet,
                'credit' => $wallet,
                'outstanding' => $outstanding,
                'net_due' => max(0, $currentDue - $wallet),
            ],
            'contracts' => $client->contracts,
            'orders' => $client->orders,
            'invoices' => $invoiceRows,
            'installments' => $installments,
            'payments' => $history,
        ];
    }

    public function storePhone(Request $request, Client $client)
    {
        $data = $request->validate([
            'country_code' => ['nullable', 'string'],
            'phone' => ['required'],
            'is_primary' => ['boolean'],
        ]);
        $data['country_code'] = $data['country_code'] ?? ClientPhone::DEFAULT_COUNTRY_CODE;

        return $client->phones()->create($data);
    }

    public function storeLocation(Request $request, Client $client)
    {
        $data = $request->validate($this->locationRules());

        return $client->locations()->create($data);
    }

    public function storeMachine(Request $request, ClientLocation $location)
    {
        $data = $request->validate([
            'brand' => ['nullable', 'string'],
            'model' => ['nullable', 'string'],
            'serial' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return $location->machines()->create($data);
    }

    public function updateMachine(Request $request, AcMachine $machine)
    {
        $machine->update($request->validate([
            'brand' => ['nullable', 'string'],
            'model' => ['nullable', 'string'],
            'serial' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]));

        return $machine;
    }

    public function updatePhone(Request $request, ClientPhone $phone)
    {
        $data = $request->validate([
            'country_code' => ['nullable', 'string'],
            'phone' => ['required', 'string'],
            'is_primary' => ['boolean'],
        ]);
        if ($request->boolean('is_primary')) {
            $phone->client->phones()->update(['is_primary' => false]);
        }
        $phone->update($data);

        return $phone->fresh();
    }

    public function destroyPhone(ClientPhone $phone)
    {
        if ($phone->orders()->exists()) {
            return response()->json(['message' => 'This number is used on a service order and cannot be deleted.'], 422);
        }
        $phone->delete();

        return response()->json(['ok' => true]);
    }

    public function updateLocation(Request $request, ClientLocation $location)
    {
        $data = $request->validate($this->locationRules());
        $location->update($data);

        return $location->fresh('machines');
    }

    public function destroyLocation(ClientLocation $location)
    {
        if ($location->orders()->exists()) {
            return response()->json(['message' => 'This location has service orders and cannot be deleted.'], 422);
        }
        if ($location->contracts()->exists()) {
            return response()->json(['message' => 'This location has contracts and cannot be deleted.'], 422);
        }
        $location->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyMachine(AcMachine $machine)
    {
        if ($machine->contracts()->exists()) {
            return response()->json(['message' => 'This machine is on a contract and cannot be deleted.'], 422);
        }
        if (InvoiceItem::query()->where('machine_id', $machine->id)->exists()) {
            return response()->json(['message' => 'This machine was used on an invoice and cannot be deleted.'], 422);
        }
        $machine->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyClient(Client $client)
    {
        if ($client->orders()->exists()) {
            return response()->json(['message' => 'This client has orders and cannot be deleted.'], 422);
        }
        if ($client->contracts()->exists()) {
            return response()->json(['message' => 'This client has contracts and cannot be deleted.'], 422);
        }
        if ($client->payments()->exists()) {
            return response()->json(['message' => 'This client has payments and cannot be deleted.'], 422);
        }
        $client->delete();

        return response()->json(['ok' => true]);
    }

    private function matchPhone($query, string $like): void
    {
        $digits = preg_replace('/\D+/', '', $like) ?: $like;
        $query->where('phone', 'like', $like)
            ->orWhere('country_code', 'like', $like)
            ->orWhereRaw("(country_code || ' ' || phone) like ?", [$like])
            ->orWhereRaw("replace(replace(replace(phone, '-', ''), ' ', ''), '+', '') like ?", [$digits]);
    }

    private function matchLocation($query, string $like): void
    {
        $query->where('label', 'like', $like)
            ->orWhere('country', 'like', $like)
            ->orWhere('city', 'like', $like)
            ->orWhere('area', 'like', $like)
            ->orWhere('block', 'like', $like)
            ->orWhere('street', 'like', $like)
            ->orWhere('avenue', 'like', $like)
            ->orWhere('building', 'like', $like)
            ->orWhere('floor', 'like', $like)
            ->orWhere('flat', 'like', $like)
            ->orWhere('extras', 'like', $like)
            ->orWhere('paci_number', 'like', $like)
            ->orWhere('google_maps_link', 'like', $like);
    }

    /**
     * @return array<string, list<string>>
     */
    private function locationRules(string $prefix = ''): array
    {
        $required = ['required', 'string'];
        $optional = ['nullable', 'string'];
        $fields = [
            'label' => $required,
            'country' => $required,
            'city' => $optional,
            'area' => $required,
            'block' => $required,
            'street' => $required,
            'avenue' => $optional,
            'building' => $optional,
            'floor' => $optional,
            'flat' => $optional,
            'extras' => $optional,
            'paci_number' => ['nullable', 'integer'],
            'google_maps_link' => $optional,
        ];

        if ($prefix === '') {
            return $fields;
        }

        $prefixed = [];
        foreach ($fields as $key => $rules) {
            $prefixed[$prefix.$key] = $rules;
        }

        return $prefixed;
    }

    /**
     * @param  list<array<string, mixed>>  $phones
     * @return list<array<string, mixed>>
     */
    private function filledPhones(array $phones): array
    {
        return array_values(array_filter(
            $phones,
            fn (array $phone) => filled($phone['phone'] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $loc
     */
    private function isBlankLocation(array $loc): bool
    {
        foreach (['city', 'area', 'block', 'street', 'avenue', 'building', 'floor', 'flat', 'extras'] as $field) {
            if (filled($loc[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
