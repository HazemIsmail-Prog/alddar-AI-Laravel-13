<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Contract;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountService;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\LedgerService;
use App\Services\Accounting\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountingController extends Controller
{
    public function accounts()
    {
        return Account::query()->withCount(['lines', 'children'])->orderBy('code')->get();
    }

    public function storeAccount(Request $request, AccountService $accounts)
    {
        $data = $request->validate($this->accountRules());

        return $this->domain(fn () => $accounts->create($data));
    }

    public function updateAccount(Request $request, Account $account, AccountService $accounts)
    {
        $data = $request->validate($this->accountRules($account->id));

        return $this->domain(fn () => $accounts->update($account, $data));
    }

    public function destroyAccount(Account $account, AccountService $accounts)
    {
        return $this->domain(function () use ($account, $accounts) {
            $accounts->delete($account);

            return ['ok' => true];
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function accountRules(?int $ignoreId = null): array
    {
        $code = ['required', 'string', 'max:20'];
        $code[] = $ignoreId
            ? 'unique:accounts,code,'.$ignoreId
            : 'unique:accounts,code';

        return [
            'code' => $code,
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ];
    }

    public function journals(Request $request)
    {
        $filters = $this->periodFilters($request, extra: [
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        return JournalEntry::query()
            ->with(['lines.account', 'poster'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source_type', $source))
            ->latest('id')
            ->limit(500)
            ->get();
    }

    public function store(Request $request, JournalPoster $journals)
    {
        $data = $this->journalPayload($request);

        return $this->domain(fn () => $journals->postManual(
            $request->user(),
            $data['date'],
            $data['description'],
            $data['lines'],
        ));
    }

    public function update(Request $request, JournalEntry $journal, JournalPoster $journals)
    {
        $data = $this->journalPayload($request);

        return $this->domain(fn () => $journals->updateManual(
            $journal,
            $data['date'],
            $data['description'],
            $data['lines'],
        ));
    }

    public function destroy(JournalEntry $journal, JournalPoster $journals)
    {
        return $this->domain(function () use ($journal, $journals) {
            $journals->deleteManual($journal);

            return ['ok' => true];
        });
    }

    /**
     * @return array{date: string, description: string, lines: list<array{account_id: int, debit: int, credit: int}>}
     */
    private function journalPayload(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.debit' => ['required', 'integer', 'min:0'],
            'lines.*.credit' => ['required', 'integer', 'min:0'],
        ]);
    }

    public function trialBalance(Request $request, LedgerService $ledger)
    {
        $filters = $this->periodFilters($request);

        return $ledger->trialBalance($filters['from'] ?? null, $filters['to'] ?? null);
    }

    public function ledger(Request $request, Account $account, LedgerService $ledger)
    {
        $filters = $this->periodFilters($request);
        $data = $ledger->ledger($account, $filters['from'] ?? null, $filters['to'] ?? null);

        return [
            'account' => $account,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            ...$data,
        ];
    }

    public function incomeStatement(Request $request, ReportService $reports)
    {
        $filters = $this->periodFilters($request);

        return $reports->incomeStatement($filters['from'] ?? null, $filters['to'] ?? null);
    }

    public function balanceSheet(Request $request, ReportService $reports)
    {
        $filters = $this->periodFilters($request, asOf: true);

        return $reports->balanceSheet($filters['as_of'] ?? null);
    }

    public function arAging(Request $request, ReportService $reports)
    {
        $filters = $this->periodFilters($request, asOf: true, extra: [
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        return $reports->arAging($filters['as_of'] ?? null, isset($filters['client_id']) ? (int) $filters['client_id'] : null);
    }

    public function collections(Request $request, ReportService $reports)
    {
        $filters = $this->periodFilters($request, extra: [
            'method' => ['nullable', 'in:cash,card,bank'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        return $reports->collections(
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['method'] ?? null,
            isset($filters['client_id']) ? (int) $filters['client_id'] : null,
        );
    }

    public function clientStatement(Request $request, ReportService $reports)
    {
        $filters = $this->periodFilters($request, extra: [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        return $reports->clientStatement(
            (int) $filters['client_id'],
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );
    }

    public function installmentReport(Request $request, ReportService $reports)
    {
        $filters = $this->periodFilters($request, asOf: true, extra: [
            'status' => ['nullable', 'in:pending,billed,paid'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        return $reports->installments(
            $filters['status'] ?? null,
            isset($filters['client_id']) ? (int) $filters['client_id'] : null,
            $request->boolean('overdue'),
            $filters['as_of'] ?? null,
        );
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array<string, mixed>
     */
    private function periodFilters(Request $request, bool $asOf = false, array $extra = []): array
    {
        $rules = [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            ...$extra,
        ];
        if ($asOf) {
            $rules['as_of'] = ['nullable', 'date'];
        }
        $data = $request->validate($rules);
        if (($data['from'] ?? null) && ($data['to'] ?? null) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages([
                'to' => 'The end date must be on or after the start date.',
            ]);
        }

        return $data;
    }

    public function valuation(LedgerService $ledger)
    {
        return $ledger->inventoryVsGl();
    }

    public function contractProfit()
    {
        return Contract::query()->with(['client', 'location', 'orders.invoices'])->get()->map(function (Contract $c) {
            $cost = $c->orders->sum(fn ($o) => (int) $o->invoices->sum('contract_cost'));

            return [
                'id' => $c->id,
                'type' => $c->type,
                'client' => $c->client->name,
                'location' => $c->location->label,
                'value' => $c->total_amount,
                'fulfillment_cost' => $cost,
            ];
        });
    }
}
