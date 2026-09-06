<?php

namespace App\Services\Accounting;

use App\Models\Account;
use InvalidArgumentException;

class AccountService
{
    public function create(array $data): Account
    {
        $this->assertUniqueCode($data['code']);

        return Account::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'parent_id' => $this->parentId($data['parent_id'] ?? null),
            'is_system' => false,
        ]);
    }

    public function update(Account $account, array $data): Account
    {
        $account->name = $data['name'];

        if ($account->is_system) {
            if (
                (isset($data['code']) && $data['code'] !== $account->code)
                || (isset($data['type']) && $data['type'] !== $account->type)
                || array_key_exists('parent_id', $data) && (int) ($data['parent_id'] ?? 0) !== (int) ($account->parent_id ?? 0)
            ) {
                throw new InvalidArgumentException('System accounts can only be renamed.');
            }
            $account->save();

            return $account->fresh();
        }

        if (isset($data['code']) && $data['code'] !== $account->code) {
            $this->assertUniqueCode($data['code'], $account->id);
            $account->code = $data['code'];
        }
        if (isset($data['type'])) {
            $account->type = $data['type'];
        }
        if (array_key_exists('parent_id', $data)) {
            $account->parent_id = $this->parentId($data['parent_id'], $account->id);
        }
        $account->save();

        return $account->fresh();
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw new InvalidArgumentException('System accounts cannot be deleted.');
        }
        if ($account->lines()->exists()) {
            throw new InvalidArgumentException('This account has journal lines and cannot be deleted.');
        }
        if ($account->children()->exists()) {
            throw new InvalidArgumentException('This account has child accounts and cannot be deleted.');
        }

        $account->delete();
    }

    private function assertUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $exists = Account::query()
            ->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw new InvalidArgumentException('Account code is already in use.');
        }
    }

    private function parentId(mixed $parentId, ?int $accountId = null): ?int
    {
        if ($parentId === null || $parentId === '' || $parentId === 0) {
            return null;
        }
        $id = (int) $parentId;
        if ($accountId && $id === $accountId) {
            throw new InvalidArgumentException('An account cannot be its own parent.');
        }
        if (! Account::query()->whereKey($id)->exists()) {
            throw new InvalidArgumentException('Parent account was not found.');
        }
        if ($accountId && $this->wouldCycle($accountId, $id)) {
            throw new InvalidArgumentException('An account cannot be nested under its own child.');
        }

        return $id;
    }

    private function wouldCycle(int $accountId, int $parentId): bool
    {
        $current = $parentId;
        $seen = [$accountId];
        while ($current) {
            if (in_array($current, $seen, true)) {
                return true;
            }
            $seen[] = $current;
            $current = Account::query()->whereKey($current)->value('parent_id');
        }

        return false;
    }
}
