<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\ContractInstallment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Services\Contracts\ContractService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(
        private JournalPoster $journals,
        private ContractService $contracts,
    ) {}

    public function collect(User $user, array $data): Payment
    {
        $amount = $this->journals->money($data['amount']);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than 0.');
        }

        return DB::transaction(function () use ($user, $data, $amount) {
            $target = $this->resolveTarget($data);
            $clientId = (int) ($data['client_id'] ?? $target['invoice']?->order?->client_id ?? $target['installment']?->contract?->client_id);
            if (! $clientId) {
                throw new InvalidArgumentException('Client is required.');
            }
            $this->assertTargetClient($target, $clientId);

            $existingWallet = $this->walletBalance($clientId);

            $payment = Payment::query()->create([
                'client_id' => $clientId,
                'received_by' => $user->id,
                'created_by' => $user->id,
                'amount' => $amount,
                'method' => $data['method'],
                'notes' => $data['notes'] ?? null,
            ]);

            $cashCode = match ($data['method']) {
                'bank' => '1010',
                default => '1000',
            };

            $this->journals->post('payment', $payment->id, 'collect', 'Payment collected', [
                ['account_id' => $this->journals->account($cashCode)->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->journals->account('2110')->id, 'debit' => 0, 'credit' => $amount],
            ], $user);

            if ($target['kind'] !== 'credit') {
                $useCredit = min(
                    max(0, $this->journals->money($data['use_credit'] ?? 0)),
                    $existingWallet,
                );
                $this->allocateToDue($user, $clientId, $target, $amount + $useCredit, $payment->id);
            }

            return $payment->load(['client', 'allocations.invoice', 'allocations.installment']);
        });
    }

    public function applyCredit(User $user, array $data): PaymentAllocation
    {
        $amount = $this->journals->money($data['amount']);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0.');
        }

        return DB::transaction(function () use ($user, $data, $amount) {
            if (($data['apply_to'] ?? null) === 'credit') {
                throw new InvalidArgumentException('Wallet can only be applied to an invoice or installment.');
            }
            $target = $this->resolveTarget($data, requireDue: true);
            $clientId = (int) ($data['client_id'] ?? $target['invoice']?->order?->client_id ?? $target['installment']?->contract?->client_id);
            if (! $clientId) {
                throw new InvalidArgumentException('Client is required.');
            }
            $this->assertTargetClient($target, $clientId);

            $wallet = $this->walletBalance($clientId);
            if ($amount > $wallet) {
                throw new InvalidArgumentException('Amount exceeds the client credit balance.');
            }

            if ($target['installment']) {
                $this->prepareInstallment($target['installment'], $user);
                $remaining = $this->installmentRemaining($target['installment']);
            } else {
                if ($target['invoice']?->status !== 'confirmed') {
                    throw new InvalidArgumentException('Only a confirmed invoice can be collected against.');
                }
                $remaining = $this->invoiceRemaining($target['invoice']);
            }
            if ($amount > $remaining) {
                throw new InvalidArgumentException('Amount exceeds the remaining balance.');
            }

            return $this->allocateToDue($user, $clientId, $target, $amount, null);
        });
    }

    public function walletBalance(int $clientId): int
    {
        $received = (int) Payment::query()->where('client_id', $clientId)->sum('amount');
        $applied = (int) PaymentAllocation::query()->where('client_id', $clientId)->sum('amount');

        return max(0, $received - $applied);
    }

    public function hydrateClientWallet(?Client $client): void
    {
        if (! $client) {
            return;
        }

        $client->setAttribute('wallet', $this->walletBalance($client->id));
    }

    public function allocatedToInvoice(int $invoiceId): int
    {
        return (int) PaymentAllocation::query()->where('invoice_id', $invoiceId)->sum('amount');
    }

    public function allocatedToInstallment(int $installmentId): int
    {
        return (int) PaymentAllocation::query()->where('installment_id', $installmentId)->sum('amount');
    }

    public function invoiceRemaining(Invoice $invoice): int
    {
        if ($invoice->status !== 'confirmed') {
            return 0;
        }

        return max(0, (int) $invoice->total - $this->allocatedToInvoice($invoice->id));
    }

    public function installmentRemaining(ContractInstallment $installment): int
    {
        if ($installment->status === 'paid') {
            return 0;
        }

        return max(0, (int) $installment->amount - $this->allocatedToInstallment($installment->id));
    }

    /**
     * @param  array{kind: string, invoice: ?Invoice, installment: ?ContractInstallment}  $target
     */
    private function allocateToDue(User $user, int $clientId, array $target, int $requested, ?int $paymentId): PaymentAllocation
    {
        $invoice = $target['invoice'];
        $installment = $target['installment'];

        if ($installment) {
            $this->prepareInstallment($installment, $user);
            $remaining = $this->installmentRemaining($installment);
        } else {
            $invoice?->loadMissing('order');
            if (! $invoice || $invoice->status !== 'confirmed') {
                throw new InvalidArgumentException('Only a confirmed invoice can be collected against.');
            }
            if ((int) $invoice->order->client_id !== $clientId) {
                throw new InvalidArgumentException('Invoice does not belong to this client.');
            }
            $remaining = $this->invoiceRemaining($invoice);
        }

        if ($remaining <= 0) {
            throw new InvalidArgumentException($installment ? 'This installment is already paid.' : 'This invoice is already paid.');
        }

        $apply = min($requested, $remaining);
        if ($apply <= 0) {
            throw new InvalidArgumentException('Nothing to apply.');
        }

        $allocation = PaymentAllocation::query()->create([
            'client_id' => $clientId,
            'payment_id' => $paymentId,
            'invoice_id' => $invoice?->id,
            'installment_id' => $installment?->id,
            'applied_by' => $user->id,
            'created_by' => $user->id,
            'amount' => $apply,
        ]);

        $this->journals->post('payment_allocation', $allocation->id, 'apply', 'Client credit applied', [
            ['account_id' => $this->journals->account('2110')->id, 'debit' => $apply, 'credit' => 0],
            ['account_id' => $this->journals->account('1100')->id, 'debit' => 0, 'credit' => $apply],
        ], $user);

        if ($installment && $this->installmentRemaining($installment) <= 0) {
            $installment->status = 'paid';
            $installment->save();
        }

        return $allocation->load(['invoice', 'installment', 'payment', 'applier']);
    }

    /**
     * @return array{kind: string, invoice: ?Invoice, installment: ?ContractInstallment}
     */
    private function resolveTarget(array $data, bool $requireDue = false): array
    {
        $invoice = isset($data['invoice_id']) ? Invoice::query()->with('order')->findOrFail($data['invoice_id']) : null;
        $installment = isset($data['installment_id'])
            ? ContractInstallment::query()->with('contract')->lockForUpdate()->findOrFail($data['installment_id'])
            : null;

        if ($invoice && $installment) {
            throw new InvalidArgumentException('Apply to an invoice or an installment, not both.');
        }

        $kind = $data['apply_to'] ?? ($invoice ? 'invoice' : ($installment ? 'installment' : 'credit'));
        if ($kind === 'credit') {
            if ($requireDue) {
                throw new InvalidArgumentException('Choose an invoice or installment to apply credit.');
            }

            return ['kind' => 'credit', 'invoice' => null, 'installment' => null];
        }
        if ($kind === 'invoice' && ! $invoice) {
            throw new InvalidArgumentException('Invoice is required.');
        }
        if ($kind === 'installment' && ! $installment) {
            throw new InvalidArgumentException('Installment is required.');
        }
        if ($requireDue && ! $invoice && ! $installment) {
            throw new InvalidArgumentException('Choose an invoice or installment to apply credit.');
        }

        return [
            'kind' => $installment ? 'installment' : 'invoice',
            'invoice' => $invoice,
            'installment' => $installment,
        ];
    }

    /**
     * @param  array{kind: string, invoice: ?Invoice, installment: ?ContractInstallment}  $target
     */
    private function assertTargetClient(array $target, int $clientId): void
    {
        if ($target['invoice'] && (int) $target['invoice']->order->client_id !== $clientId) {
            throw new InvalidArgumentException('Invoice does not belong to this client.');
        }
        if ($target['installment'] && (int) $target['installment']->contract->client_id !== $clientId) {
            throw new InvalidArgumentException('Installment does not belong to this client.');
        }
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array{wallet: int, outstanding: int, net_due: int}>
     */
    public function balancesFor(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        $blank = ['wallet' => 0, 'outstanding' => 0, 'net_due' => 0];
        $balances = array_fill_keys($clientIds, $blank);
        if (! $clientIds) {
            return [];
        }

        $received = Payment::query()
            ->whereIn('client_id', $clientIds)
            ->selectRaw('client_id, sum(amount) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        $applied = PaymentAllocation::query()
            ->whereIn('client_id', $clientIds)
            ->selectRaw('client_id, sum(amount) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        $invoiceDue = Invoice::query()
            ->join('service_orders', 'service_orders.id', '=', 'invoices.order_id')
            ->where('invoices.status', 'confirmed')
            ->whereIn('service_orders.client_id', $clientIds)
            ->selectRaw('service_orders.client_id as client_id, invoices.total')
            ->selectRaw('(select coalesce(sum(amount), 0) from payment_allocations where invoice_id = invoices.id) as paid')
            ->get()
            ->groupBy('client_id')
            ->map(fn ($rows) => (int) $rows->sum(fn ($row) => max(0, (int) $row->total - (int) $row->paid)));

        $today = now()->toDateString();
        $installmentRows = ContractInstallment::query()
            ->join('contracts', 'contracts.id', '=', 'contract_installments.contract_id')
            ->whereIn('contracts.client_id', $clientIds)
            ->where('contract_installments.status', '!=', 'paid')
            ->selectRaw('contracts.client_id as client_id, contract_installments.amount, contract_installments.due_date')
            ->selectRaw('(select coalesce(sum(amount), 0) from payment_allocations where installment_id = contract_installments.id) as paid')
            ->get();

        $remainingOf = fn ($rows) => (int) $rows->sum(fn ($row) => max(0, (int) $row->amount - (int) $row->paid));
        $allInstallmentDue = $installmentRows->groupBy('client_id')->map($remainingOf);
        $currentInstallmentDue = $installmentRows
            ->filter(fn ($row) => substr((string) $row->due_date, 0, 10) <= $today)
            ->groupBy('client_id')
            ->map($remainingOf);

        foreach ($clientIds as $id) {
            $wallet = max(0, (int) $received->get($id, 0) - (int) $applied->get($id, 0));
            $invoices = (int) $invoiceDue->get($id, 0);
            $outstanding = $invoices + (int) $allInstallmentDue->get($id, 0);
            $currentDue = $invoices + (int) $currentInstallmentDue->get($id, 0);
            $balances[$id] = [
                'wallet' => $wallet,
                'outstanding' => $outstanding,
                'net_due' => max(0, $currentDue - $wallet),
            ];
        }

        return $balances;
    }

    /**
     * @param  iterable<int, \App\Models\ServiceOrder>  $orders
     */
    public function attachOrderNetDue(iterable $orders): void
    {
        $items = collect($orders)->filter();
        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $dueByOrder = Invoice::query()
            ->whereIn('order_id', $ids)
            ->where('status', 'confirmed')
            ->selectRaw('order_id, total')
            ->selectRaw('(select coalesce(sum(amount), 0) from payment_allocations where invoice_id = invoices.id) as paid')
            ->get()
            ->groupBy('order_id')
            ->map(fn ($rows) => (int) $rows->sum(fn ($row) => max(0, (int) $row->total - (int) $row->paid)));

        foreach ($items as $order) {
            $order->setAttribute('net_due', (int) $dueByOrder->get((int) $order->id, 0));
        }
    }

    /**
     * @param  iterable<int, \App\Models\Contract>  $contracts
     */
    public function attachContractNetDue(iterable $contracts): void
    {
        $items = collect($contracts)->filter();
        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $today = now()->toDateString();
        $dueByContract = ContractInstallment::query()
            ->whereIn('contract_id', $ids)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', $today)
            ->selectRaw('contract_id, amount')
            ->selectRaw('(select coalesce(sum(amount), 0) from payment_allocations where installment_id = contract_installments.id) as paid')
            ->get()
            ->groupBy('contract_id')
            ->map(fn ($rows) => (int) $rows->sum(fn ($row) => max(0, (int) $row->amount - (int) $row->paid)));

        foreach ($items as $contract) {
            $contract->setAttribute('net_due', (int) $dueByContract->get((int) $contract->id, 0));
        }
    }

    private function prepareInstallment(ContractInstallment $installment, User $user): void
    {
        if ($installment->status === 'paid') {
            throw new InvalidArgumentException('This installment is already paid.');
        }

        $this->contracts->billInstallment($installment, $user);
        $installment->refresh();
        if ($installment->status === 'pending') {
            throw new InvalidArgumentException('This installment is not ready to collect.');
        }
    }
}
