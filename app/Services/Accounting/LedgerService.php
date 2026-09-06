<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalLine;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LedgerService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * @return list<array{id: int, code: string, name: string, type: string, opening: int, debit: int, credit: int, movement: int, balance: int}>
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $opening = $from ? $this->accountSums(null, Carbon::parse($from)->subDay()->toDateString()) : collect();
        $period = $this->accountSums($from, $to);

        return Account::query()->orderBy('code')->get()->map(function (Account $account) use ($opening, $period) {
            $od = (int) ($opening[$account->id]->debit ?? 0);
            $oc = (int) ($opening[$account->id]->credit ?? 0);
            $pd = (int) ($period[$account->id]->debit ?? 0);
            $pc = (int) ($period[$account->id]->credit ?? 0);

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'opening' => $this->balance($account->type, $od, $oc),
                'debit' => $pd,
                'credit' => $pc,
                'movement' => $this->balance($account->type, $pd, $pc),
                'balance' => $this->balance($account->type, $od + $pd, $oc + $pc),
            ];
        })->all();
    }

    /**
     * @return array{opening: int, closing: int, lines: list<array{id: int, date: string, description: string, source_type: string, debit: int, credit: int, balance: int}>}
     */
    public function ledger(Account $account, ?string $from = null, ?string $to = null): array
    {
        $openingDebit = 0;
        $openingCredit = 0;
        if ($from) {
            $opening = $this->accountSums(null, Carbon::parse($from)->subDay()->toDateString());
            $openingDebit = (int) ($opening[$account->id]->debit ?? 0);
            $openingCredit = (int) ($opening[$account->id]->credit ?? 0);
        }
        $running = $this->balance($account->type, $openingDebit, $openingCredit);

        $lines = JournalLine::query()
            ->with('journal')
            ->where('account_id', $account->id)
            ->whereHas('journal', function ($q) use ($from, $to) {
                $q->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                    ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));
            })
            ->get()
            ->sortBy(fn (JournalLine $line) => sprintf('%s-%010d', $line->journal->date->toDateString(), $line->id))
            ->values();

        $out = [];
        foreach ($lines as $line) {
            $running = $this->apply($account->type, $running, (int) $line->debit, (int) $line->credit);
            $out[] = [
                'id' => $line->id,
                'date' => $line->journal->date->toDateString(),
                'description' => $line->journal->description,
                'source_type' => $line->journal->source_type,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
                'balance' => $running,
            ];
        }

        return [
            'opening' => $this->balance($account->type, $openingDebit, $openingCredit),
            'closing' => $running,
            'lines' => $out,
        ];
    }

    public function inventoryVsGl(): array
    {
        $inventory = Account::query()->where('code', '1200')->first();
        $inTransit = Account::query()->where('code', '1210')->first();
        $tb = collect($this->trialBalance())->keyBy('code');

        return [
            'on_hand_value' => $this->inventory->onHandTotal(),
            'in_transit_value' => $this->inventory->inTransitTotal(),
            'gl_inventory' => $tb['1200']['balance'] ?? 0,
            'gl_in_transit' => $tb['1210']['balance'] ?? 0,
            'rows' => $this->inventory->valuationRows(),
            'accounts' => compact('inventory', 'inTransit'),
        ];
    }

    /**
     * @return Collection<int, object{debit: int|string, credit: int|string}>
     */
    private function accountSums(?string $from, ?string $to): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_id')
            ->when($from, fn ($q) => $q->whereDate('journal_entries.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('journal_entries.date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->get()
            ->keyBy('account_id');
    }

    public function balance(string $type, int $debit, int $credit): int
    {
        return in_array($type, ['asset', 'expense'], true)
            ? $debit - $credit
            : $credit - $debit;
    }

    private function apply(string $type, int $running, int $debit, int $credit): int
    {
        $delta = in_array($type, ['asset', 'expense'], true)
            ? $debit - $credit
            : $credit - $debit;

        return $running + $delta;
    }
}
