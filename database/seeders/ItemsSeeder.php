<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

class ItemsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (require __DIR__.'/data/items.php' as $row) {
            Item::query()->updateOrCreate(['sku' => $row['sku']], $row + ['created_by' => 1]);
        }
    }
}
