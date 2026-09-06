<?php

namespace App\Services\Contracts;

use App\Models\AcMachine;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\JournalEntry;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Support\StaffRealtime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContractService
{
    public function __construct(private JournalPoster $journals) {}

    public function create(array $data, User $user): Contract
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data, $user) {
            $this->assertMachinesOnLocation($data['machine_ids'], (int) $data['location_id']);

            $contract = Contract::query()->create($this->attributes($data, 'active') + ['created_by' => $user->id]);
            $contract->machines()->sync($data['machine_ids']);

            if ($data['explicit_installments']) {
                $this->syncInstallments($contract, $data['installments']);
            } elseif ($contract->type === 'annual' && $contract->payment_count > 0) {
                $this->createInstallments($contract);
            }

            if ($data['explicit_visits']) {
                $this->syncVisits($contract, $user, $data['visits']);
            } else {
                $this->createPlannedVisits($contract, $user);
            }

            return $contract->loadDetails();
        });
    }

    public function update(Contract $contract, array $data, User $user): Contract
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($contract, $data, $user) {
            $contract->load(['installments.allocations', 'orders']);
            $this->assertMachinesOnLocation($data['machine_ids'], (int) $data['location_id']);

            if ($this->financeFieldsChanged($contract, $data) && $this->financeLocked($contract)) {
                throw new InvalidArgumentException(
                    'Contract value, type, and installment count cannot change after an installment is billed or paid.'
                );
            }

            $status = $this->resolveStatus($data, $contract);
            $rebuildFinance = ! $data['explicit_installments']
                && ($this->financeFieldsChanged($contract, $data)
                    || ($this->datesChanged($contract, $data) && ! $this->financeLocked($contract)));

            $contract->fill($this->attributes($data, $status))->save();
            $contract->machines()->sync($data['machine_ids']);

            if ($data['explicit_installments']) {
                $this->syncInstallments($contract, $data['installments']);
            } elseif ($rebuildFinance) {
                $this->rebuildInstallments($contract);
            }

            if ($data['explicit_visits']) {
                $this->syncVisits($contract, $user, $data['visits']);
            } else {
                $this->syncPlannedVisits($contract, $user);
            }

            return $contract->loadDetails();
        });
    }

    public function billDueInstallments(?User $user = null): int
    {
        $due = ContractInstallment::query()
            ->with('contract')
            ->where('status', 'pending')
            ->whereDate('due_date', '<=', now())
            ->get();

        $count = 0;
        foreach ($due as $installment) {
            if ($this->billInstallment($installment, $user)) {
                $count++;
            }
        }

        return $count;
    }

    public function billInstallment(ContractInstallment $installment, ?User $user = null): bool
    {
        $installment->loadMissing('contract');
        if ($installment->status !== 'pending' || $installment->contract?->type !== 'annual') {
            return false;
        }

        $this->journals->post(
            'contract_installment',
            $installment->id,
            'due',
            'Contract installment due',
            [
                ['account_id' => $this->journals->account('1100')->id, 'debit' => $installment->amount, 'credit' => 0],
                ['account_id' => $this->journals->account('2100')->id, 'debit' => 0, 'credit' => $installment->amount],
            ],
            $user,
            $installment->due_date,
        );
        $installment->status = 'billed';
        $installment->save();

        return true;
    }

    public function recognizeRevenue(?User $user = null): int
    {
        $count = 0;
        $contracts = Contract::query()->where('type', 'annual')->where('status', 'active')->get();

        foreach ($contracts as $contract) {
            $months = max(1, $contract->start_date->diffInMonths($contract->end_date) ?: 1);
            $monthly = intdiv((int) $contract->total_amount, $months);
            $key = 'recognize-'.now()->format('Y-m');
            $existing = JournalEntry::query()
                ->where('source_type', 'contract')
                ->where('source_id', $contract->id)
                ->where('event_key', $key)
                ->exists();
            if ($existing || $monthly === 0) {
                continue;
            }
            if (now()->lt($contract->start_date) || now()->gt($contract->end_date->endOfDay())) {
                continue;
            }
            $this->journals->post('contract', $contract->id, $key, 'Contract revenue recognition', [
                ['account_id' => $this->journals->account('2100')->id, 'debit' => $monthly, 'credit' => 0],
                ['account_id' => $this->journals->account('4020')->id, 'debit' => 0, 'credit' => $monthly],
            ], $user);
            $count++;
        }

        return $count;
    }

    public function expireContracts(): int
    {
        return Contract::query()
            ->where('status', 'active')
            ->whereDate('end_date', '<', now())
            ->update(['status' => 'expired']);
    }

    private function normalize(array $data): array
    {
        $explicitInstallments = array_key_exists('installments', $data) && is_array($data['installments']);
        $explicitVisits = array_key_exists('visits', $data) && is_array($data['visits']);
        $type = $data['type'];
        $includesCompressor = (bool) ($data['includes_compressor_warranty'] ?? false);

        if ($type === 'warranty') {
            $includesCompressor = true;
            $data['includes_spare_parts'] = true;
            $data['total_amount'] = 0;
            $data['payment_count'] = 0;
            $data['installments'] = [];
        } else {
            if (! isset($data['total_amount']) || $data['total_amount'] <= 0) {
                throw new InvalidArgumentException('Annual contracts require a contract value greater than 0.');
            }
            if ($explicitInstallments) {
                $data['installments'] = $this->normalizeInstallmentRows($data['installments']);
                $data['payment_count'] = count($data['installments']);
            }
            if (($data['payment_count'] ?? 0) < 1) {
                throw new InvalidArgumentException('Annual contracts require at least one payment.');
            }
        }

        if ($includesCompressor) {
            $data['includes_compressor_warranty'] = true;
            $data['compressor_warranty_start'] ??= $data['start_date'];
            $data['compressor_warranty_end'] ??= Carbon::parse($data['compressor_warranty_start'])->addYears(5)->toDateString();
        } else {
            $data['includes_compressor_warranty'] = false;
            $data['compressor_warranty_start'] = null;
            $data['compressor_warranty_end'] = null;
        }

        if ($includesCompressor && (empty($data['compressor_warranty_start']) || empty($data['compressor_warranty_end']))) {
            throw new InvalidArgumentException('Compressor warranty start and end dates are required.');
        }

        if ($explicitVisits) {
            $data['visits'] = $this->normalizeVisitRows($data['visits']);
            $data['planned_visits'] = count($data['visits']);
        }

        $data['includes_spare_parts'] = (bool) ($data['includes_spare_parts'] ?? false);
        $data['machine_ids'] = array_values(array_unique(array_map('intval', $data['machine_ids'] ?? [])));
        $data['planned_visits'] = (int) ($data['planned_visits'] ?? 0);
        $data['payment_count'] = (int) ($data['payment_count'] ?? 0);
        $data['explicit_installments'] = $explicitInstallments;
        $data['explicit_visits'] = $explicitVisits;

        if ($type === 'annual' && $explicitInstallments) {
            $this->assertInstallmentSum($data['installments'], $data['total_amount']);
        }

        return $data;
    }

    private function attributes(array $data, string $status): array
    {
        return [
            'client_id' => $data['client_id'],
            'location_id' => $data['location_id'],
            'department_id' => $data['department_id'],
            'type' => $data['type'],
            'includes_spare_parts' => $data['includes_spare_parts'],
            'includes_compressor_warranty' => (bool) $data['includes_compressor_warranty'],
            'compressor_warranty_start' => $data['compressor_warranty_start'] ?? null,
            'compressor_warranty_end' => $data['compressor_warranty_end'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_amount' => $data['total_amount'],
            'payment_count' => $data['payment_count'],
            'planned_visits' => $data['planned_visits'],
            'status' => $status,
        ];
    }

    private function assertMachinesOnLocation(array $ids, int $locationId): void
    {
        if (! $ids) {
            return;
        }

        $count = AcMachine::query()->where('location_id', $locationId)->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            throw new InvalidArgumentException('Covered machines must belong to the contract location.');
        }
    }

    private function financeLocked(Contract $contract): bool
    {
        return $contract->installments->contains(
            fn ($row) => in_array($row->status, ['billed', 'paid'], true) || $row->allocations->isNotEmpty()
        );
    }

    private function financeFieldsChanged(Contract $contract, array $data): bool
    {
        return $contract->type !== $data['type']
            || (int) $contract->total_amount !== (int) $data['total_amount'];
    }

    private function datesChanged(Contract $contract, array $data): bool
    {
        return $contract->start_date->toDateString() !== $data['start_date']
            || $contract->end_date->toDateString() !== $data['end_date'];
    }

    private function resolveStatus(array $data, Contract $contract): string
    {
        $requested = $data['status'] ?? $contract->status;
        if ($requested === 'cancelled') {
            return 'cancelled';
        }

        return Carbon::parse($data['end_date'])->lt(now()->startOfDay()) ? 'expired' : 'active';
    }

    private function normalizeInstallmentRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (empty($row['due_date'])) {
                throw new InvalidArgumentException('Each installment needs a due date.');
            }
            $out[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'due_date' => $row['due_date'],
                'amount' => $row['amount'] ?? 0,
                'description' => filled($row['description'] ?? null) ? trim((string) $row['description']) : null,
            ];
        }

        return $out;
    }

    private function normalizeVisitRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (empty($row['planned_date'])) {
                throw new InvalidArgumentException('Each planned visit needs a date.');
            }
            $out[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'planned_date' => $row['planned_date'],
            ];
        }

        return $out;
    }

    private function assertInstallmentSum(array $rows, mixed $total): void
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) $row['amount'];
        }
        if ($sum !== (int) $total) {
            throw new InvalidArgumentException('Installment amounts must add up to the contract value.');
        }
    }

    private function syncInstallments(Contract $contract, array $rows): void
    {
        $contract->load(['installments.allocations']);

        if ($contract->type !== 'annual') {
            if ($this->financeLocked($contract)) {
                throw new InvalidArgumentException(
                    'Contract value, type, and installment count cannot change after an installment is billed or paid.'
                );
            }
            $contract->installments()->delete();

            return;
        }

        $existing = $contract->installments->keyBy('id');
        $kept = [];

        foreach ($rows as $row) {
            $id = $row['id'] ? (int) $row['id'] : null;
            if ($id) {
                $inst = $existing->get($id);
                if (! $inst) {
                    throw new InvalidArgumentException('Installment does not belong to this contract.');
                }
                $locked = in_array($inst->status, ['billed', 'paid'], true) || $inst->allocations->isNotEmpty();
                $attrs = ['description' => $row['description']];
                if (! $locked) {
                    $attrs['due_date'] = $row['due_date'];
                    $attrs['amount'] = $row['amount'];
                }
                $inst->fill($attrs)->save();
                $kept[] = $id;

                continue;
            }

            $created = $contract->installments()->create([
                'due_date' => $row['due_date'],
                'amount' => $row['amount'],
                'description' => $row['description'],
                'status' => 'pending',
                'created_by' => $contract->created_by,
            ]);
            $kept[] = $created->id;
        }

        foreach ($existing as $inst) {
            if (in_array($inst->id, $kept, true)) {
                continue;
            }
            $locked = in_array($inst->status, ['billed', 'paid'], true) || $inst->allocations->isNotEmpty();
            if ($locked) {
                throw new InvalidArgumentException('Billed or paid installments cannot be removed.');
            }
            $inst->delete();
        }
    }

    private function syncVisits(Contract $contract, User $user, array $rows): void
    {
        $orders = $contract->orders()
            ->where('source', 'contract_schedule')
            ->orderBy('id')
            ->get();
        $byId = $orders->keyBy('id');
        $sticky = ['assigned', 'accepted', 'reached', 'completed'];
        $immutableDates = ['accepted', 'reached', 'completed'];

        foreach ($orders as $order) {
            if (! in_array($order->status, $sticky, true)) {
                continue;
            }
            $found = collect($rows)->contains(fn ($row) => (int) ($row['id'] ?? 0) === $order->id);
            if (! $found) {
                throw new InvalidArgumentException(
                    'Cannot remove visits that are already assigned or in progress.'
                );
            }
        }

        $kept = [];
        foreach ($rows as $row) {
            $id = $row['id'] ? (int) $row['id'] : null;
            if ($id) {
                $order = $byId->get($id);
                if (! $order) {
                    throw new InvalidArgumentException('Visit does not belong to this contract.');
                }
                $attrs = [
                    'client_id' => $contract->client_id,
                    'phone_id' => $this->visitPhoneId($contract),
                    'location_id' => $contract->location_id,
                    'department_id' => $contract->department_id,
                ];
                if (! in_array($order->status, $immutableDates, true)) {
                    $attrs['planned_date'] = $row['planned_date'];
                }
                $order->fill($attrs)->save();
                $kept[] = $id;

                continue;
            }

            $created = $this->createVisit($contract, $user, $row['planned_date']);
            $kept[] = $created->id;
        }

        foreach ($orders as $order) {
            if (in_array($order->id, $kept, true) || $order->status === 'cancelled') {
                continue;
            }
            $order->delete();
        }
    }

    private function rebuildInstallments(Contract $contract): void
    {
        if ($this->financeLocked($contract)) {
            throw new InvalidArgumentException(
                'Contract value, type, and installment count cannot change after an installment is billed or paid.'
            );
        }

        $contract->installments()->delete();

        if ($contract->type === 'annual' && $contract->payment_count > 0) {
            $this->createInstallments($contract);
        }
    }

    private function createInstallments(Contract $contract): void
    {
        $n = (int) $contract->payment_count;
        $each = intdiv((int) $contract->total_amount, $n);
        $allocated = 0;
        $start = $contract->start_date->copy();
        $days = max(1, $start->diffInDays($contract->end_date));

        for ($i = 0; $i < $n; $i++) {
            $amount = $i === $n - 1
                ? (int) $contract->total_amount - $allocated
                : $each;
            $allocated += $amount;
            $due = $start->copy()->addDays((int) floor($days * $i / $n));
            $contract->installments()->create([
                'due_date' => $due->toDateString(),
                'amount' => $amount,
                'status' => 'pending',
                'created_by' => $contract->created_by,
            ]);
        }
    }

    private function createPlannedVisits(Contract $contract, User $user): void
    {
        foreach ($this->visitDates($contract, (int) $contract->planned_visits) as $planned) {
            $this->createVisit($contract, $user, $planned);
        }
    }

    private function syncPlannedVisits(Contract $contract, User $user): void
    {
        $target = (int) $contract->planned_visits;
        $orders = $contract->orders()
            ->where('source', 'contract_schedule')
            ->orderBy('planned_date')
            ->orderBy('id')
            ->get();

        $stickyStatuses = ['assigned', 'accepted', 'reached', 'completed'];
        $sticky = $orders->filter(fn ($order) => in_array($order->status, $stickyStatuses, true))->values();
        $removable = $orders->filter(fn ($order) => in_array($order->status, ['pending', 'on_hold'], true))->values();

        if ($target < $sticky->count()) {
            throw new InvalidArgumentException(
                'Cannot reduce planned visits below visits that are already assigned or in progress.'
            );
        }

        $keep = $target - $sticky->count();
        $extra = $removable->slice($keep);
        foreach ($extra as $order) {
            $order->delete();
        }
        $kept = $removable->take($keep)->values();

        $dates = $this->visitDates($contract, $target);
        $assign = array_slice($dates, $sticky->count());

        foreach ($kept as $i => $order) {
            $order->fill([
                'client_id' => $contract->client_id,
                'phone_id' => $this->visitPhoneId($contract),
                'location_id' => $contract->location_id,
                'department_id' => $contract->department_id,
                'planned_date' => $assign[$i],
            ])->save();
        }

        foreach ($orders->where('status', 'assigned') as $order) {
            $order->fill([
                'client_id' => $contract->client_id,
                'phone_id' => $this->visitPhoneId($contract),
                'location_id' => $contract->location_id,
                'department_id' => $contract->department_id,
            ])->save();
        }

        $missing = $target - $sticky->count() - $kept->count();
        $newDates = array_slice($assign, $kept->count());
        foreach (array_slice($newDates, 0, $missing) as $planned) {
            $this->createVisit($contract, $user, $planned);
        }
    }

    private function visitDates(Contract $contract, int $n): array
    {
        if ($n < 1) {
            return [];
        }

        $days = max(1, $contract->start_date->diffInDays($contract->end_date));
        $dates = [];
        for ($i = 0; $i < $n; $i++) {
            $dates[] = $contract->start_date->copy()->addDays((int) floor($days * $i / $n))->toDateString();
        }

        return $dates;
    }

    private function createVisit(Contract $contract, User $user, string $planned): ServiceOrder
    {
        $order = ServiceOrder::query()->create([
            'client_id' => $contract->client_id,
            'phone_id' => $this->visitPhoneId($contract),
            'location_id' => $contract->location_id,
            'department_id' => $contract->department_id,
            'contract_id' => $contract->id,
            'created_by' => $user->id,
            'source' => 'contract_schedule',
            'status' => 'pending',
            'planned_date' => $planned,
            'notes' => 'Scheduled contract visit',
        ]);
        $order->statusHistory()->create([
            'user_id' => $user->id,
            'from_status' => null,
            'to_status' => 'pending',
            'reason' => 'Contract planned visit',
            'created_by' => $user->id,
        ]);
        StaffRealtime::order($order, 'created');

        return $order;
    }

    private function visitPhoneId(Contract $contract): int
    {
        $contract->loadMissing('client.phones');
        $phone = $contract->client?->defaultPhone();
        if (! $phone) {
            throw new InvalidArgumentException('Client must have a phone number before visits can be scheduled.');
        }

        return (int) $phone->id;
    }
}
