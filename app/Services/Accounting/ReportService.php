<?php

namespace App\Services\Accounting;

use App\Models\Client;
use App\Models\ContractInstallment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Carbon\Carbon;

class ReportService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * @return array{from: ?string, to: ?string, income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, income_total: int, expense_total: int, net_income: int}
     */
    public function incomeStatement(?string $from, ?string $to): array
    {
        $rows = collect($this->ledger->trialBalance($from, $to));
        $income = $rows->where('type', 'revenue')->map(fn ($row) => $this->pnlRow($row))->values()->all();
        $expenses = $rows->where('type', 'expense')->map(fn ($row) => $this->pnlRow($row))->values()->all();
        $incomeTotal = (int) collect($income)->sum('amount');
        $expenseTotal = (int) collect($expenses)->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'expenses' => $expenses,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net_income' => $incomeTotal - $expenseTotal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(?string $asOf): array
    {
        $rows = collect($this->ledger->trialBalance(null, $asOf));
        $assets = $rows->where('type', 'asset')->map(fn ($row) => $this->sheetRow($row))->values()->all();
        $liabilities = $rows->where('type', 'liability')->map(fn ($row) => $this->sheetRow($row))->values()->all();
        $equity = $rows->where('type', 'equity')->map(fn ($row) => $this->sheetRow($row))->values()->all();
        $netIncome = $this->incomeStatement(null, $asOf)['net_income'];
        $assetTotal = (int) collect($assets)->sum('amount');
        $liabilityTotal = (int) collect($liabilities)->sum('amount');
        $equityPosted = (int) collect($equity)->sum('amount');
        $equityTotal = $equityPosted + $netIncome;

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'net_income' => $netIncome,
            'asset_total' => $assetTotal,
            'liability_total' => $liabilityTotal,
            'equity_total' => $equityTotal,
            'liabilities_and_equity' => $liabilityTotal + $equityTotal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function arAging(?string $asOf, ?int $clientId): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $end = Carbon::parse($asOf)->endOfDay();
        $buckets = ['current' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'days_90' => 0];
        $rows = [];

        $invoices = Invoice::query()
            ->with(['order.client'])
            ->where('status', 'confirmed')
            ->where('confirmed_at', '<=', $end)
            ->when($clientId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('client_id', $clientId)))
            ->get();

        foreach ($invoices as $invoice) {
            $allocated = (int) PaymentAllocation::query()
                ->where('invoice_id', $invoice->id)
                ->where('created_at', '<=', $end)
                ->sum('amount');
            $remaining = max(0, (int) $invoice->total - $allocated);
            if ($remaining <= 0) {
                continue;
            }
            $start = $invoice->confirmed_at?->toDateString() ?? $invoice->created_at->toDateString();
            $row = $this->agingRow(
                'invoice',
                $invoice->id,
                $invoice->order?->client?->name ?? '',
                $invoice->order?->client_id,
                $start,
                $asOf,
                (int) $invoice->total,
                $remaining,
                'Invoice #'.$invoice->id,
            );
            $buckets[$row['bucket']] += $remaining;
            $rows[] = $row;
        }

        $installments = ContractInstallment::query()
            ->with(['contract.client'])
            ->whereIn('status', ['billed', 'paid'])
            ->whereDate('due_date', '<=', $asOf)
            ->when($clientId, fn ($q) => $q->whereHas('contract', fn ($c) => $c->where('client_id', $clientId)))
            ->get();

        foreach ($installments as $installment) {
            $allocated = (int) PaymentAllocation::query()
                ->where('installment_id', $installment->id)
                ->where('created_at', '<=', $end)
                ->sum('amount');
            $remaining = max(0, (int) $installment->amount - $allocated);
            if ($remaining <= 0) {
                continue;
            }
            $row = $this->agingRow(
                'installment',
                $installment->id,
                $installment->contract?->client?->name ?? '',
                $installment->contract?->client_id,
                $installment->due_date->toDateString(),
                $asOf,
                (int) $installment->amount,
                $remaining,
                $installment->description ?: 'Installment #'.$installment->id,
            );
            $buckets[$row['bucket']] += $remaining;
            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => $b['days'] <=> $a['days']);

        return [
            'as_of' => $asOf,
            'buckets' => $buckets,
            'total' => array_sum($buckets),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collections(?string $from, ?string $to, ?string $method, ?int $clientId): array
    {
        $rows = Payment::query()
            ->with(['client', 'receiver'])
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->when($method, fn ($q) => $q->where('method', $method))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'date' => $payment->created_at->toDateString(),
                'client_id' => $payment->client_id,
                'client' => $payment->client?->name,
                'method' => $payment->method,
                'amount' => (int) $payment->amount,
                'notes' => $payment->notes,
                'received_by' => $payment->receiver?->toNamePayload(),
            ])
            ->all();

        $byMethod = collect($rows)->groupBy('method')->map(fn ($group) => (int) $group->sum('amount'))->all();

        return [
            'from' => $from,
            'to' => $to,
            'total' => (int) collect($rows)->sum('amount'),
            'by_method' => $byMethod,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clientStatement(int $clientId, ?string $from, ?string $to): array
    {
        $client = Client::query()->findOrFail($clientId);
        $events = [];

        $invoices = Invoice::query()
            ->whereHas('order', fn ($q) => $q->where('client_id', $clientId))
            ->where('status', 'confirmed')
            ->get();
        foreach ($invoices as $invoice) {
            $events[] = [
                'date' => $invoice->confirmed_at?->toDateString() ?? $invoice->created_at->toDateString(),
                'sort' => ($invoice->confirmed_at ?? $invoice->created_at)->timestamp,
                'kind' => 'invoice',
                'description' => 'Invoice #'.$invoice->id,
                'charged' => (int) $invoice->total,
                'paid' => 0,
            ];
        }

        $installments = ContractInstallment::query()
            ->whereHas('contract', fn ($q) => $q->where('client_id', $clientId))
            ->whereIn('status', ['billed', 'paid'])
            ->get();
        foreach ($installments as $installment) {
            $events[] = [
                'date' => $installment->due_date->toDateString(),
                'sort' => $installment->due_date->copy()->startOfDay()->timestamp,
                'kind' => 'installment',
                'description' => $installment->description ?: 'Installment #'.$installment->id,
                'charged' => (int) $installment->amount,
                'paid' => 0,
            ];
        }

        $allocations = PaymentAllocation::query()
            ->where('client_id', $clientId)
            ->get();
        foreach ($allocations as $allocation) {
            $events[] = [
                'date' => $allocation->created_at->toDateString(),
                'sort' => $allocation->created_at->timestamp,
                'kind' => 'applied',
                'description' => $allocation->invoice_id
                    ? 'Applied to invoice #'.$allocation->invoice_id
                    : 'Applied to installment #'.$allocation->installment_id,
                'charged' => 0,
                'paid' => (int) $allocation->amount,
            ];
        }

        usort($events, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $opening = 0;
        $running = 0;
        $lines = [];
        foreach ($events as $event) {
            $delta = $event['charged'] - $event['paid'];
            if ($from && $event['date'] < $from) {
                $opening += $delta;

                continue;
            }
            if ($to && $event['date'] > $to) {
                continue;
            }
            if ($lines === []) {
                $running = $opening;
            }
            $running += $delta;
            $lines[] = [
                'date' => $event['date'],
                'kind' => $event['kind'],
                'description' => $event['description'],
                'charged' => $event['charged'],
                'paid' => $event['paid'],
                'balance' => $running,
            ];
        }
        if ($lines === []) {
            $running = $opening;
        }

        $asOf = $to ?: now()->toDateString();
        $received = (int) Payment::query()
            ->where('client_id', $clientId)
            ->whereDate('created_at', '<=', $asOf)
            ->sum('amount');
        $applied = (int) PaymentAllocation::query()
            ->where('client_id', $clientId)
            ->whereDate('created_at', '<=', $asOf)
            ->sum('amount');
        $wallet = max(0, $received - $applied);
        $owed = max(0, $running);

        return [
            'client' => ['id' => $client->id, 'name' => $client->name],
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'charged' => (int) collect($lines)->sum('charged'),
            'paid' => (int) collect($lines)->sum('paid'),
            'owed' => $owed,
            'wallet' => $wallet,
            'net_due' => max(0, $owed - $wallet),
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function installments(?string $status, ?int $clientId, bool $overdueOnly, ?string $asOf): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $rows = ContractInstallment::query()
            ->with(['contract.client'])
            ->withSum('allocations', 'amount')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($clientId, fn ($q) => $q->whereHas('contract', fn ($c) => $c->where('client_id', $clientId)))
            ->orderBy('due_date')
            ->get()
            ->map(function (ContractInstallment $installment) use ($asOf) {
                $allocated = (int) ($installment->allocations_sum_amount ?? 0);
                $remaining = max(0, (int) $installment->amount - $allocated);
                $overdue = $remaining > 0 && $installment->due_date->toDateString() < $asOf && $installment->status !== 'paid';

                return [
                    'id' => $installment->id,
                    'client' => $installment->contract?->client?->name,
                    'client_id' => $installment->contract?->client_id,
                    'contract_id' => $installment->contract_id,
                    'due_date' => $installment->due_date->toDateString(),
                    'description' => $installment->description,
                    'amount' => (int) $installment->amount,
                    'remaining' => $remaining,
                    'status' => $overdue ? 'overdue' : $installment->status,
                    'overdue' => $overdue,
                ];
            })
            ->when($overdueOnly, fn ($rows) => $rows->where('overdue', true)->values())
            ->values()
            ->all();

        return [
            'as_of' => $asOf,
            'total_remaining' => (int) collect($rows)->sum('remaining'),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{code: string, name: string, movement: int}  $row
     * @return array{code: string, name: string, amount: int}
     */
    private function pnlRow(array $row): array
    {
        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'amount' => (int) $row['movement'],
        ];
    }

    /**
     * @param  array{code: string, name: string, balance: int}  $row
     * @return array{code: string, name: string, amount: int}
     */
    private function sheetRow(array $row): array
    {
        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'amount' => (int) $row['balance'],
        ];
    }

    /**
     * @return array{kind: string, id: int, client: string, client_id: ?int, date: string, days: int, bucket: string, original: int, remaining: int, description: string}
     */
    private function agingRow(
        string $kind,
        int $id,
        string $client,
        ?int $clientId,
        string $start,
        string $asOf,
        int $original,
        int $remaining,
        string $description,
    ): array {
        $days = (int) Carbon::parse($start)->startOfDay()->diffInDays(Carbon::parse($asOf)->startOfDay());
        $bucket = 'current';
        if ($days > 90) {
            $bucket = 'days_90';
        } elseif ($days > 60) {
            $bucket = 'days_61_90';
        } elseif ($days > 30) {
            $bucket = 'days_31_60';
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'client' => $client,
            'client_id' => $clientId,
            'date' => $start,
            'days' => $days,
            'bucket' => $bucket,
            'original' => $original,
            'remaining' => $remaining,
            'description' => $description,
        ];
    }
}
