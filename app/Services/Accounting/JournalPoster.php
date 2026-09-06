<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class JournalPoster
{
    public function post(
        string $sourceType,
        int $sourceId,
        string $eventKey,
        string $description,
        array $lines,
        ?User $user = null,
        $date = null,
    ): JournalEntry {
        $existing = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event_key', $eventKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $normalized = $this->normalizeLines($lines);
        $creatorId = $user?->id ?? auth()->id();
        if (! $creatorId) {
            throw new InvalidArgumentException('created_by requires an authenticated user.');
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $eventKey, $description, $normalized, $user, $date, $creatorId) {
            $entry = JournalEntry::query()->create([
                'date' => $date ?? now()->toDateString(),
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'event_key' => $eventKey,
                'posted_by' => $user?->id,
                'created_by' => $creatorId,
            ]);

            $entry->lines()->createMany(array_map(
                fn (array $line) => $line + ['created_by' => $creatorId],
                $normalized,
            ));

            return $entry->load('lines');
        });
    }

    public function postManual(User $user, string $date, string $description, array $lines): JournalEntry
    {
        $normalized = $this->normalizeLines($lines);

        return DB::transaction(function () use ($user, $date, $description, $normalized) {
            $entry = JournalEntry::query()->create([
                'date' => $date,
                'description' => $description,
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'source_id' => 0,
                'event_key' => (string) Str::ulid(),
                'posted_by' => $user->id,
                'created_by' => $user->id,
            ]);
            $entry->update(['source_id' => $entry->id]);
            $entry->lines()->createMany(array_map(
                fn (array $line) => $line + ['created_by' => $user->id],
                $normalized,
            ));

            return $entry->load(['lines.account', 'poster']);
        });
    }

    public function updateManual(JournalEntry $entry, string $date, string $description, array $lines): JournalEntry
    {
        if (! $entry->is_manual) {
            throw new InvalidArgumentException('Only manual journals can be edited.');
        }

        $normalized = $this->normalizeLines($lines);

        return DB::transaction(function () use ($entry, $date, $description, $normalized) {
            $entry->update([
                'date' => $date,
                'description' => $description,
            ]);
            $entry->lines()->delete();
            $creatorId = $entry->created_by ?: auth()->id();
            $entry->lines()->createMany(array_map(
                fn (array $line) => $line + ['created_by' => $creatorId],
                $normalized,
            ));

            return $entry->fresh(['lines.account', 'poster']);
        });
    }

    public function deleteManual(JournalEntry $entry): void
    {
        if (! $entry->is_manual) {
            throw new InvalidArgumentException('Only manual journals can be deleted.');
        }

        $entry->delete();
    }

    public function reverse(string $sourceType, int $sourceId, string $originalKey, string $reverseKey, ?User $user = null): ?JournalEntry
    {
        $original = JournalEntry::query()
            ->with('lines')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event_key', $originalKey)
            ->first();

        if (! $original) {
            return null;
        }

        $lines = $original->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
        ])->all();

        return $this->post(
            $sourceType,
            $sourceId,
            $reverseKey,
            'Reversal: '.$original->description,
            $lines,
            $user,
        );
    }

    public function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    public function money(mixed $value): int
    {
        return (int) $value;
    }

    /**
     * @param  list<array{account_id?: mixed, debit?: mixed, credit?: mixed}>  $lines
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    private function normalizeLines(array $lines): array
    {
        $debit = 0;
        $credit = 0;
        $normalized = [];

        foreach ($lines as $line) {
            $d = $this->money($line['debit'] ?? 0);
            $c = $this->money($line['credit'] ?? 0);
            if ($d === 0 && $c === 0) {
                continue;
            }
            if ($d > 0 && $c > 0) {
                throw new InvalidArgumentException('A line cannot have both debit and credit.');
            }
            $debit += $d;
            $credit += $c;
            $normalized[] = [
                'account_id' => (int) $line['account_id'],
                'debit' => $d,
                'credit' => $c,
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Journal has no lines.');
        }

        if (count($normalized) < 2) {
            throw new InvalidArgumentException('Journal needs at least two lines.');
        }

        if ($debit !== $credit) {
            throw new InvalidArgumentException("Unbalanced journal: debit {$debit} credit {$credit}.");
        }

        return $normalized;
    }
}
