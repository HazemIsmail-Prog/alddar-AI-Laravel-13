<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
            ['code' => '1010', 'name' => 'Bank', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
            ['code' => '1210', 'name' => 'Inventory in Transit', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => '2100', 'name' => 'Unearned Contract Revenue', 'type' => 'liability'],
            ['code' => '2110', 'name' => 'Client Credit', 'type' => 'liability'],
            ['code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Service Revenue', 'type' => 'revenue'],
            ['code' => '4010', 'name' => 'Parts Revenue', 'type' => 'revenue'],
            ['code' => '4020', 'name' => 'Contract Revenue', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense'],
            ['code' => '5010', 'name' => 'Contract Fulfillment Cost', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Discounts', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'Inventory Adjustment', 'type' => 'expense'],
        ];

        foreach ($accounts as $row) {
            Account::query()->updateOrCreate(['code' => $row['code']], $row + ['is_system' => true]);
        }
    }
}
